<?php

namespace App\Services;

use Doctrine\DBAL\Connection;

class ManifestQuizDestination implements EditorialQuizDestination
{
    protected const RECIPE = 'editorial';

    public function __construct(private readonly QuizCommandDestination $destination)
    {
    }

    public function verify(Connection $db, array $options, string $action = 'import'): array
    {
        EditorialQuizManifest::require(in_array($action, ['import', 'enable', 'disable'], true), 'Action éditoriale invalide.');
        foreach (['env', 'database', 'host', 'port', 'server'] as $field) {
            EditorialQuizManifest::require(is_string($options['expect-'.$field] ?? null) && '' !== $options['expect-'.$field], '--expect-'.$field.' requis.');
        }
        if ('prod' === $options['expect-env']) {
            EditorialQuizManifest::require(0 !== strcasecmp('orthogram', $options['expect-database']), 'La base de travail orthogram est réservée au contexte dev.');

            return $this->destination->verify($db, $options);
        }
        if ('dev' === $options['expect-env'] && 'orthogram' === $options['expect-database']) {
            EditorialQuizManifest::require('import' === $action, 'Renouvellement interdit sur orthogram en dev.');
            if ($options['apply'] ?? false) {
                EditorialQuizManifest::require(true === ($options['allow-work-database'] ?? false), '--allow-work-database requis pour importer sur orthogram.');
                EditorialQuizManifest::require('127.0.0.1' === $options['expect-host'] && '3306' === $options['expect-port']
                    && 'STEF-LEGION' === $options['expect-server'], 'Destination différente de la base de travail validée.');
            }
        } else {
            EditorialQuizManifest::require('test' === $options['expect-env']
                && 1 === preg_match('/^orthogram_'.static::RECIPE.'_[a-f0-9]{16}_test$/D', $options['expect-database']), 'Base jetable locale de recette requise.');
        }
        $params = $db->getParams();
        EditorialQuizManifest::require($options['expect-database'] === ($params['dbname'] ?? null)
            && in_array($params['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true), 'Connexion locale à la base attendue requise.');

        $context = $this->destination->verify($db, $options, editorialTestRecipe: static::RECIPE);
        EditorialQuizManifest::require((int) $db->fetchOne('SELECT @@port') === $context['port'], 'Port effectif différent.');

        return $context;
    }
}
