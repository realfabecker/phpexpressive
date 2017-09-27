<?php

namespace Solis\Expressive\Classes\Illuminate\Select;

use Solis\Expressive\Schema\Contracts\Entries\Property\PropertyContract;
use Solis\Expressive\Contracts\ExpressiveContract;
use Solis\Expressive\Classes\Illuminate\Wrapper;
use Solis\Expressive\Classes\Illuminate\Diglett;
use Solis\Breaker\Abstractions\TExceptionAbstract;
use Solis\Expressive\Exception;

/**
 * Class SelectBuilder
 *
 * @package Solis\Expressive\Classes\Illuminate\Select
 */
final class SelectBuilder
{

    /**
     * @var RelationshipBuilder
     */
    protected $relationshipBuilder;

    /**
     * SelectBuilder constructor.
     */
    public function __construct()
    {
        $this->setRelationshipBuilder(new RelationshipBuilder());
    }

    /**
     * @return RelationshipBuilder
     */
    public function getRelationshipBuilder()
    {
        return $this->relationshipBuilder;
    }

    /**
     * @param RelationshipBuilder $relationshipBuilder
     */
    public function setRelationshipBuilder($relationshipBuilder)
    {
        $this->relationshipBuilder = $relationshipBuilder;
    }

    /**
     * @param ExpressiveContract $model
     * @param array              $arguments
     * @param array              $options
     *
     * @return ExpressiveContract[]|boolean
     *
     * @throws TExceptionAbstract
     */
    public function select(
        ExpressiveContract $model,
        array $arguments,
        array $options = []
    ) {
        $table = $model::$schema->getRepository();

        $Builder = new StmtBuilder($table, $arguments, $options);

        $stmt = $Builder->where()->orderBy()->limit()->getStmt();

        $columns = $this->columns($model, $options);

        try {
            $result = $stmt->get($columns)->toArray();
        } catch (\PDOException $exception) {
            throw new Exception($exception->getMessage(), 500);
        }

        return empty($result) ? $result : $this->fetchDependencies($model, $result, $Builder->dependencies());
    }

    /**
     * @param ExpressiveContract $model
     * @param array              $options
     *
     * @return array
     *
     * @throws TExceptionAbstract
     */
    private function columns(
        $model,
        $options
    ) {
        $searchAll = ['*'];

        $withProperties = $options['withProperties'] ?? null;

        if (empty($withProperties)) {
            return $searchAll;
        }

        $withProperties = !is_array($withProperties) ? [$withProperties] : $withProperties;

        // caso fornecido o array withProperties, essa deverá conter a relação de propriedades
        // do registro que serão retornados pela consulta. Em caso de relacionamento, considera
        // apenas relacionamento do tipo hasOne. Caso vazio, retorna todas as propriedades.
        //
        // Vale notar que, caso utilizado em conjunto com withDependencies, onde uma propriedade
        // representar um relacionamento hasOne, se essa não estiver relacionada no conjunto de
        // propriedades, essa não será exibida. E, em caso de relacionamento hasMany, essa também
        // não será retornada caso os campos que compoe o relacionamento não forem também listados

        $columns = $model::$schema->getSearchableFieldsString();

        $searchFor = array_values(array_filter($columns, function ($field) use ($withProperties) {
            return in_array($field, $withProperties);
        }));

        return !empty($searchFor) ? $searchFor : $searchAll;
    }

    /**
     * @param ExpressiveContract $model
     * @param array              $rows
     * @param mixed              $dependencies
     *
     * @return array
     */
    protected function fetchDependencies(ExpressiveContract $model, array $rows, $dependencies)
    {
        $class = get_class($model);
        $instances = array_map(function ($item) use ($class, $dependencies) {
            $instance = Wrapper::fetchStdClassToExpressiveNewModel($item, $class);

            return !Diglett::toDig() ? $instance : $this->dependencies($instance, $dependencies);
        }, $rows);

        return $instances;
    }

    /**
     * @param ExpressiveContract $model
     *
     * @param boolean            $dependencies
     *
     * @return ExpressiveContract|boolean
     *
     * @throws TExceptionAbstract
     */
    public function search(ExpressiveContract $model, $dependencies = true)
    {
        $table = $model::$schema->getRepository();

        $Builder = new StmtBuilder($table);

        $stmt = $Builder->whereKey($model)->getStmt();

        try {
            $result = $stmt->get()->toArray();
        } catch (\PDOException $exception) {
            throw new Exception($exception->getMessage(), 500);
        }

        if (empty($result) || count($result) > 1) {
            return false;
        }

        $instance = Wrapper::fetchStdClassToExpressiveModel($result[0], $model);

        return !Diglett::toDig() || !$dependencies ? $instance : $this->dependencies($instance, true);
    }

    /**
     * @param array              $arguments
     * @param ExpressiveContract $model
     *
     * @return int
     *
     * @throws TExceptionAbstract
     */
    public function count(
        ExpressiveContract $model,
        array $arguments = []
    ) {
        $table = $model::$schema->getRepository();

        $Builder = new StmtBuilder($table, $arguments);

        $stmt = $Builder->where()->getStmt();

        try {
            $result = $stmt->count();
        } catch (\PDOException $exception) {
            throw new Exception($exception->getMessage(), 500);
        }

        return $result;
    }

    /**
     * @param ExpressiveContract $model
     * @param boolean $dependencies
     *
     * @return ExpressiveContract|bool
     *
     * @throws TExceptionAbstract
     */
    public function last(ExpressiveContract $model, $dependencies = true)
    {

        $options = [
            'limit'            => [
                'number' => 1,
            ],
            'orderBy'          => [],
            'withDependencies' => $dependencies,
        ];

        $arguments = [];
        foreach ($model::$schema->getKeys() as $key) {
            // Se uma chave possuir comportamento auto incremental, então o consumidor não é
            // responsável por sua atribuição, desse modo ela será utilizada como filtro de
            // ordenação para a operação de consulta.
            if ($key->getBehavior()->isAutoIncrement()) {
                $options['orderBy'][] = [
                    'column'    => $key->getField(),
                    'direction' => 'desc',
                ];

                continue;
            }

            $value = $model->{$key->getProperty()};

            // Caso houver valor atribuido ao model em uma propriedade definida como chave
            // essa será atribuida como filtro de consulta, permitindo situacoes em que o
            // registro possui chave composta.
            if (!empty($value)) {
                $arguments[] = [
                    'column' => $key->getField(),
                    'value'  => $value,
                ];
            }
        }

        $select = $this->select($model, $arguments, $options);

        return $select[0] ?? $select;
    }

    /**
     * @param ExpressiveContract $model
     * @param array|boolean      $dependenciItems
     *
     * @return ExpressiveContract
     *
     * @throws TExceptionAbstract
     */
    public function dependencies(
        $model,
        $dependenciItems
    ) {
        // O valor atribuido a propriedade $dependenciItems poderá assumir valor valor
        // boolean ou array.
        //
        // Caso boolean e TRUE, todas as dependências serão retornadas, caso boolean e
        // FALSE, nenhuma dependéncia será retornada pela consulta.
        //
        // Caso array, esse deverá conter o nome das dependencias vinculadas ao registro
        // que serão retornadas pela consulta. Se array vazio, nenhuma dependência será
        // retornada pela operação.
        if (empty($dependenciItems)) {
            return $model;
        }

        $dependencies = $model::$schema->getDependencies();
        if (empty($dependencies)) {
            return $model;
        }

        // se array, filtra as dependencias a serem retornadas somente se no conjunto de
        // entradas desejadas para consulta.
        if (is_array($dependenciItems)) {
            $dependencies = array_filter($dependencies, function (PropertyContract $property) use ($dependenciItems) {
                return in_array($property->getProperty(), $dependenciItems);
            });
        }

        if (empty($dependencies)) {
            return $model;
        }

        foreach ($dependencies as $dependency) {
            if (empty($dependency->getComposition()->getRelationship())) {
                throw new Exception('a dependency must have a relationship assigned to it in the schema', 400);
            }

            // chama o método de consulta de acordo com os tipos de relacionamento
            // válidos atribuidos a entrada no schema vinculado ao registro.
            $relationship = $dependency->getComposition()->getRelationship()->getType();

            $model = $this->getRelationshipBuilder()->{$relationship}($model, $dependency);
        }

        return $model;
    }
}
