<?php

namespace App\Services;

use Doctrine\DBAL\Connection;

/** Local import and renewal require explicit consent; remote/production remain forbidden. */
final class ModuleTwoQuizDestination
{
    public function __construct(private readonly QuizCommandDestination $destination)
    {
    }

    /** @param array<string, mixed> $options
     * @return array{environment: string, database: string, host: string, port: int, server: string} */
    public function verify(Connection $db, array $options, string $action = 'import'): array
    {
        ModuleTwoQuizManifest::require(in_array($action, ['import', 'enable', 'disable'], true), 'Action module 2 invalide.');
        ModuleTwoQuizManifest::require(in_array($db->getParams()['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true), 'Module 2 : destinations distantes interdites.');
        ModuleTwoQuizManifest::require('prod' !== ($options['expect-env'] ?? null), 'Module 2 : environnement de production interdit.');
        $write = (bool) ($options['apply'] ?? false);
        $allowWork = (bool) ($options['allow-work-database'] ?? false);
        $options['apply'] = false;
        $context = $this->destination->verify($db, $options);
        ModuleTwoQuizManifest::require(in_array($context['environment'], ['dev', 'test'], true), 'Module 2 : environnement de production interdit.');
        ModuleTwoQuizManifest::require((int) $db->fetchOne('SELECT @@port') === $context['port'], 'Module 2 : port effectif du serveur différent.');
        if ($write) {
            foreach (['env', 'host', 'port', 'server', 'database'] as $field) {
                ModuleTwoQuizManifest::require(is_string($options['expect-'.$field] ?? null) && '' !== $options['expect-'.$field], '--expect-'.$field.' requis pour écrire.');
            }
            $disposable = 'test' === $context['environment'] && 1 === preg_match('/^orthogram_module2_[a-f0-9]{16}_test$/D', $context['database']);
            $work = 'dev' === $context['environment'] && 'orthogram' === $context['database'];
            ModuleTwoQuizManifest::require($disposable || ($work && $allowWork), 'Écriture module 2 : base jetable de recette, ou orthogram en dev avec --allow-work-database requis.');
        }

        return $context;
    }
}
