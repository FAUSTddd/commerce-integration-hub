<?php

declare(strict_types=1);

/**
 * E2E test fixture generator for Commerce Integration Hub.
 *
 * Prepares synthetic CommerceML offers payload and seeds initial import run state.
 * STRICT RUNTIME REQUIREMENT: Must run exclusively under APP_ENV=test and on commerce_db_test database.
 */

require_once 'vendor/autoload.php';

(new \Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');

$xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация ВерсияСхемы="2.09" ДатаФормирования="2026-11-06T09:00:00">
    <ПакетПредложений СодержитТолькоИзменения="false">
        <Ид>offers-e2e-uuid-1</Ид>
        <Наименование>Тестовый пакет E2E</Наименование>
        <Предложения>
            <Предложение>
                <Ид>prod-success-002#var-1</Ид>
                <Наименование>Тестовое предложение 1</Наименование>
                <Количество>10.000</Количество>
                <Цены>
                    <Цена>
                        <ЦенаЗаЕдиницу>1500.00</ЦенаЗаЕдиницу>
                        <Валюта>RUB</Валюта>
                    </Цена>
                </Цены>
            </Предложение>
        </Предложения>
    </ПакетПредложений>
</КоммерческаяИнформация>
XML;

$targetFile = 'var/import/offers_e2e_test_20261106.xml';
file_put_contents($targetFile, $xmlContent);
$fileSha256 = hash_file('sha256', $targetFile);

$kernel = new \App\Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');

/** @var \Doctrine\DBAL\Connection $conn */
$conn = $container->get('doctrine.dbal.default_connection');

$appEnv = (string) ($container->hasParameter('kernel.environment') ? $container->getParameter('kernel.environment') : ($_SERVER['APP_ENV'] ?? ''));
$databaseName = (string) $conn->getDatabase();

if ($appEnv !== 'test' || $databaseName !== 'commerce_db_test') {
    fwrite(STDERR, sprintf(
        "CRITICAL ERROR: Refusing to run outside test environment.
Expected APP_ENV=test and DB=commerce_db_test, got APP_ENV='%s' and DB='%s'.
",
        $appEnv,
        $databaseName
    ));
    exit(1);
}
/** @var \Symfony\Component\Messenger\Transport\Serialization\SerializerInterface $serializer */
$serializer = $container->has(\Symfony\Component\Messenger\Transport\Serialization\SerializerInterface::class)
    ? $container->get(\Symfony\Component\Messenger\Transport\Serialization\SerializerInterface::class)
    : new \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer();

$conn->beginTransaction();
try {
    $runId = (int) $conn->fetchOne(
        'INSERT INTO import_runs (type, filename, file_hash, status, attempt, items_count, created_at)
         VALUES (:type, :filename, :fileHash, :status, :attempt, :itemsCount, NOW())
         RETURNING id',
        [
            'type' => 'offers',
            'filename' => basename($targetFile),
            'fileHash' => $fileSha256,
            'status' => 'PENDING',
            'attempt' => 0,
            'itemsCount' => 0,
        ]
    );

    $command = new \FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand($targetFile);
    $envelope = (new \Symfony\Component\Messenger\Envelope($command))->with(new \App\Messenger\ImportRunStamp($runId));
    $encoded = $serializer->encode($envelope);

    $idempotencyKey = 'e2e_init_offers_' . $runId . '_' . bin2hex(random_bytes(8));
    $headers = $encoded['headers'] ?? ['type' => get_class($command)];
    $payload = ['body' => $encoded['body']];

    $outboxId = (int) $conn->fetchOne(
        'INSERT INTO import_run_outbox (
            run_id,
            attempt,
            kind,
            idempotency_key,
            queue_name,
            headers,
            payload,
            available_at,
            created_at
        ) VALUES (
            :runId,
            :attempt,
            :kind,
            :idempotencyKey,
            :queueName,
            :headers::jsonb,
            :payload::jsonb,
            NOW(),
            NOW()
        ) RETURNING id',
        [
            'runId' => $runId,
            'attempt' => 1,
            'kind' => 'retry',
            'idempotencyKey' => $idempotencyKey,
            'queueName' => 'offers_test',
            'headers' => json_encode($headers, JSON_THROW_ON_ERROR),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]
    );

    $conn->commit();

    echo json_encode([
        'status' => 'OK',
        'file' => $targetFile,
        'sha256' => $fileSha256,
        'run_id' => $runId,
        'outbox_id' => $outboxId,
        'queue_name' => 'offers_test',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

} catch (\Throwable $e) {
    if ($conn->isTransactionActive()) {
        $conn->rollBack();
    }
    echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]) . PHP_EOL;
    exit(1);
}
