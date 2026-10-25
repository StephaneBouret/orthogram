<?php

namespace App\Services;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Exact destination assertions, separate production consent, no credentials in reports. */
final class QuizCommandDestination
{
    public function __construct(#[Autowire('%kernel.environment%')] private readonly string $environment)
    {
    }

    /** @param array<string, mixed> $options
     * @return array{environment: string, database: string, host: string, port: int, server: string}
     */
    public function verify(Connection $db, array $options): array
    {
        $production = 'prod' === $this->environment;
        $write = (bool) ($options['apply'] ?? false);
        $allowProduction = (bool) ($options['allow-production-database'] ?? false);
        $allowWork = (bool) ($options['allow-work-database'] ?? false);
        $expectedEnv = $options['expect-env'] ?? null;
        ModuleOneReserveManifest::require(in_array($this->environment, ['dev', 'test', 'prod'], true), 'Environnement non autorisé.');
        ModuleOneReserveManifest::require(null === $expectedEnv || $expectedEnv === $this->environment, 'Environnement différent de --expect-env.');
        ModuleOneReserveManifest::require(!$allowProduction || $production, '--allow-production-database exige l’environnement prod.');
        ModuleOneReserveManifest::require(!$production || !$allowWork, '--allow-work-database ne vaut jamais autorisation de production.');
        if ($production) {
            ModuleOneReserveManifest::require('prod' === $expectedEnv, '--expect-env=prod requis, y compris en prévisualisation.');
            foreach (['expect-database', 'expect-host', 'expect-port', 'expect-server'] as $key) {
                ModuleOneReserveManifest::require(is_string($options[$key] ?? null) && '' !== $options[$key], '--'.$key.' requis en production.');
            }
            ModuleOneReserveManifest::require(!$write || $allowProduction, '--allow-production-database requis pour écrire en production.');
        }
        $params = $db->getParams();
        $host = $params['host'] ?? '';
        $port = (int) ($params['port'] ?? 3306);
        ModuleOneReserveManifest::require('' !== $host && empty($params['unix_socket']), 'Hôte MySQL explicite requis ; unix_socket configuré non pris en charge.');
        ModuleOneReserveManifest::require($production || in_array($host, ['127.0.0.1', 'localhost', '::1'], true), 'MySQL local uniquement hors environnement prod.');
        ModuleOneReserveManifest::require(null === ($options['expect-host'] ?? null) || $host === $options['expect-host'], 'Hôte différent de --expect-host.');
        if (null !== ($options['expect-port'] ?? null)) {
            ModuleOneReserveManifest::require(is_string($options['expect-port']) && 1 === preg_match('/^[1-9][0-9]{0,4}$/D', $options['expect-port'])
                && (int) $options['expect-port'] <= 65535 && $port === (int) $options['expect-port'], 'Port différent de --expect-port.');
        }
        $row = $db->fetchAssociative('SELECT DATABASE() AS db, @@hostname AS server, @@port AS server_port');
        ModuleOneReserveManifest::require(false !== $row && is_string($row['db']) && is_string($row['server']), 'Connexion MySQL effective non identifiée.');
        ModuleOneReserveManifest::require(is_string($options['expect-database'] ?? null) && $row['db'] === $options['expect-database'], 'Base effective différente de --expect-database.');
        ModuleOneReserveManifest::require(null === ($options['expect-server'] ?? null) || $row['server'] === $options['expect-server'], 'Serveur différent de --expect-server.');
        ModuleOneReserveManifest::require(!$production || (int) $row['server_port'] === $port, 'Port du serveur MySQL différent du port attendu.');
        if ($write && !$production) {
            ModuleOneReserveManifest::require(('orthogram' === $row['db'] && $allowWork)
                || 1 === preg_match('/^orthogram_(quiz_reserve_lot3_[a-f0-9]{12}|dictation_2a_[a-f0-9]{16})(_test)?$/D', $row['db']), 'Écriture réservée à une copie identifiée ; orthogram exige --allow-work-database.');
        }

        return ['environment' => $this->environment, 'database' => $row['db'], 'host' => $host, 'port' => $port, 'server' => $row['server']];
    }
}
