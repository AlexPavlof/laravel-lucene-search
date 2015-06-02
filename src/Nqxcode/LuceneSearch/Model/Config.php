<?php namespace Nqxcode\LuceneSearch\Model;

use Illuminate\Database\Eloquent\Model;
use ZendSearch\Lucene\Search\QueryHit;

/**
 * Class Config
 * @package Nqxcode\LuceneSearch
 */
class Config
{
    /**
     * The list of configurations for each searchable model.
     *
     * @var array
     */
    private $configuration = [];

    /**
     * Model factory.
     *
     * @var \Nqxcode\LuceneSearch\Model\Factory
     */
    private $modelFactory;

    /**
     * Create configuration for models.
     *
     * @param array $configuration
     * @param Factory $modelFactory
     * @throws \InvalidArgumentException
     */
    public function __construct(array $configuration, Factory $modelFactory)
    {
        $this->modelFactory = $modelFactory;

        foreach ($configuration as $className => $options) {

            $fields = array_get($options, 'fields', []);
            $optionalAttributes = array_get($options, 'optional_attributes', []);

            if (count($fields) == 0 && count($optionalAttributes) == 0) {
                throw new \InvalidArgumentException(
                    "Parameter 'fields' and/or 'optional_attributes ' for '{$className}' class must be specified."
                );
            }

            $modelRepository = $modelFactory->newInstance($className);
            $classUid = $modelFactory->classUid($className);

            $this->configuration[] = [
                'repository' => $modelRepository,
                'class_uid' => $classUid,
                'fields' => $fields,
                'optional_attributes' => $optionalAttributes,
                'private_key' => array_get($options, 'private_key', 'id')
            ];
        }
    }

    /**
     * Get configuration for model.
     *
     * @param Model $model
     * @return array
     * @throws \InvalidArgumentException
     */
    private function config(Model $model)
    {
        $classUid = $this->modelFactory->classUid($model);

        foreach ($this->configuration as $config) {
            if ($config['class_uid'] === $classUid) {
                return $config;
            }
        }

        throw new \InvalidArgumentException(
            "Configuration doesn't exist for model of class '" . get_class($model) . "'."
        );
    }

    /**
     * Create instance of model by class UID.
     *
     * @param $classUid
     * @return Model
     * @throws \InvalidArgumentException
     */
    private function createModelByClassUid($classUid)
    {
        foreach ($this->configuration as $config) {
            if ($config['class_uid'] == $classUid) {
                return $config['repository'];
            }
        }

        throw new \InvalidArgumentException("Can't find class for classUid: '{$classUid}'.");
    }

    /**
     * Get list of models instances.
     *
     * @return Model[]
     */
    public function modelRepositories()
    {
        $repositories = [];
        foreach ($this->configuration as $config) {
            $repositories[] = $config['repository'];
        }
        return $repositories;
    }

    /**
     * Get 'key-value' pair for private key of model.
     *
     * @param Model $model
     * @return array
     */
    public function privateKeyPair(Model $model)
    {
        $c = $this->config($model);
        return ['private_key', $model->{$c['private_key']}];
    }

    /**
     * Get 'key-value' pair for UID of model class.
     *
     * @param Model $model
     * @return array
     */
    public function classUidPair(Model $model)
    {
        $c = $this->config($model);
        return ['class_uid', $c['class_uid']];
    }

    /**
     * Get fields for indexing for model.
     *
     * @param Model $model
     * @return array
     */
    public function fields(Model $model)
    {
        $c = $this->config($model);
        return $c['fields'];
    }

    /**
     * Get optional attributes for indexing for model.
     *
     * @param Model $model
     * @return array
     */
    public function optionalAttributes(Model $model)
    {
        $c = $this->config($model);

        $attributes = [];
        $optionalAttributes = array_get($c, 'optional_attributes');

        $field = null;
        if (is_array($optionalAttributes)) {
            $field = array_get($optionalAttributes, 'field');
        } elseif ($optionalAttributes == true) {
            $field = 'optional_attributes';
        }

        if (!is_null($field)) {
            $attributes = array_get($model, $field, []);
        }

        return $attributes;
    }

    /**
     * Get the model by query hit.
     *
     * @param QueryHit $hit
     * @return \Illuminate\Database\Eloquent\Collection|Model|static
     */
    public function model(QueryHit $hit)
    {
        $repo = $this->createModelByClassUid($hit->class_uid);
        $model = $repo->find($hit->private_key);

        return $model;
    }

    /**
     * Get all models by query hits.
     *
     * @param QueryHit[] $hits
     * @param array $options - limit  : max number of records to return
     *                          - offset : number of records to skip
     * @param int|null $totalCount
     * @return array
     */
    public function models($hits, array $options = [], &$totalCount = null)
    {
        $models = [];
        $results = [];

        $totalCount = count($hits);

        if (isset($options['limit']) && isset($options['offset'])) {
            $hits = array_slice($hits, $options['offset'], $options['limit']);
        }

        array_map(function($hit) use (&$models) {
            $models[$hit->class_uid][] = $hit->private_key;
        }, $hits);

        if(isset($options['limit']) && isset($options['offset'])) {
            foreach ($models as $class_uid => $values) {
                $repo = $this->createModelByClassUid($class_uid);
                $res = $repo->with('page')->whereIn('id', $values)->get();
                foreach($res as $k => $v) {
                    array_push($results, $v);
                }
            }
        }

        $results = array_values($results);

        return $results;
    }
}
