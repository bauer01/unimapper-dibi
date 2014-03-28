<?php

namespace UniMapper\Mapper;

use UniMapper\Exceptions\MapperException;

/**
 * Dibi mapper can be generally used to communicate between repository and
 * dibi database abstract layer.
 */
class DibiMapper extends \UniMapper\Mapper
{

    /** @var \DibiConnection $connection Dibi connection */
    protected $connection;

    /** @var array $modificators Dibi modificators */
    protected $modificators = array(
        "boolean" => "%b",
        "integer" => "%i",
        "string" => "%s",
        "NULL" => "NULL",
        "DateTime" => "%t",
        "array" => "%in",
        "double" => "%f"
    );

    public function __construct($name, \DibiConnection $connection)
    {
        parent::__construct($name);
        $this->connection = $connection;
    }

    /**
     * Custom query
     *
     * @param \UniMapper\Query\Custom $query Query
     *
     * @return mixed
     */
    public function custom(\UniMapper\Query\Custom $query)
    {
        if ($query->method === \UniMapper\Query\Custom::METHOD_RAW) {
            return $this->connection->query($query->query)->fetchAll();
        }

        throw new MapperException("Not implemented!");
    }

    /**
     * Modify result value eg. convert DibiDateTime do Datetime etc.
     *
     * @param mixed $value Value
     *
     * @return mixed
     */
    protected function beforeCreateValue($value)
    {
        if ($value instanceof \DibiDateTime) {
            return new \DateTime($value);
        }
        return $value;
    }

    private function setConditions(\DibiFluent $fluent, array $conditions)
    {
        $i = 0;
        foreach ($conditions as $condition) {

            list($joiner, $query, $modificators) = $this->convertCondition($condition);

            array_unshift($modificators, $query);

            if ($joiner === "AND" || $i === 0) {
                $fluent->where(array($modificators));
            } else {
                $fluent->or(array($modificators));
            }
            $i++;
        }
    }

    public function convertCondition(array $condition)
    {
        if (is_array($condition[0])) {
            // Nested conditions

            list($nestedConditions, $joiner) = $condition;

            $i = 0;
            $query = "";
            $modificators = array();
            foreach ($nestedConditions as $nestedCondition) {
                list($conditionJoiner, $conditionQuery, $conditionModificators) = $this->convertCondition($nestedCondition);
                if ($i > 0) {
                    $query .= " " . $conditionJoiner . " ";
                }
                $query .= $conditionQuery;
                $modificators = array_merge($modificators, $conditionModificators);
                $i++;
            }
            return array(
                $joiner,
                "(" . $query . ")",
                $modificators
            );
        } else {
            // Simple condition

            list($columnName, $operator, $value, $joiner) = $condition;

            // Convert data type definition to dibi modificator
            $type = gettype($value);
            if ($type === "object") {
                $type = get_class($type);
            }
            if (!isset($this->modificators[$type])) {
                throw new MapperException("Value type " . $type . " is not supported");
            }

            // Get operator
            if ($operator === "COMPARE") {
                if ($this->connection->getDriver() instanceof \DibiPostgreDriver) {
                    $operator = "ILIKE";
                } elseif ($this->connection->getDriver() instanceof \DibiMySqlDriver) {
                    $operator = "LIKE";
                }
            }

            return array(
                $joiner,
                "%n %sql " . $this->modificators[$type],
                array(
                    $columnName,
                    $operator,
                    $value
                )
            );
        }
    }

    /**
     * Delete
     *
     * @param \UniMapper\Query\Delete $query Query
     *
     * @return mixed
     */
    public function delete(\UniMapper\Query\Delete $query)
    {
        // @todo this should prevent deleting all data, but it can be solved after primarProperty implement in better way
        if (count($query->conditions) === 0) {
            throw new MapperException("At least one condition must be specified!");
        }

        $fluent = $this->connection->delete($this->getResource($query->entityReflection));
        $this->setConditions($fluent, $this->translateConditions($query->entityReflection, $query->conditions));
        return $fluent->execute();
    }

    /**
     * Find single record
     *
     * @param \UniMapper\Query\FindOne $query Query
     */
    public function findOne(\UniMapper\Query\FindOne $query)
    {
        $selection = $this->getSelection($query->entityReflection);

        $fluent = $this->connection
            ->select("[" . implode("],[", $selection) . "]")
            ->from("%n", $this->getResource($query->entityReflection));

        $primaryProperty = $query->entityReflection->getPrimaryProperty();
        if ($primaryProperty === null) {
            throw new MapperException("Primary property is not set in  " .  $query->entityReflection->getName() . "!");
        }

        $condition = array($primaryProperty->getName(), "=", $query->primaryValue, "AND");
        $this->setConditions($fluent, $this->translateConditions($query->entityReflection, array($condition)));

        $result = $fluent->fetch();

        if ($result) {
            return $this->createEntity($query->entityReflection->getName(), $result);
        }
        return false;
    }

    /**
     * FindAll
     *
     * @param \UniMapper\Query\FindAll $query FindAll Query
     *
     * @return \UniMapper\EntityCollection|false
     */
    public function findAll(\UniMapper\Query\FindAll $query)
    {
        $selection = $this->getSelection($query->entityReflection, $query->selection);

        $fluent = $this->connection
            ->select("[" . implode("],[", $selection) . "]")
            ->from("%n", $this->getResource($query->entityReflection));

        $this->setConditions($fluent, $this->translateConditions($query->entityReflection, $query->conditions));

        if ($query->limit > 0) {
            $fluent->limit("%i", $query->limit);
        }

        if ($query->offset > 0) {
            $fluent->offset("%i", $query->offset);
        }

        if (count($query->orderBy) > 0) {

            foreach ($query->orderBy as $orderBy) {

                // Map property name to defined mapping definition
                $properties = $query->entityReflection->getProperties($this->name);

                // Skip properties not related to this mapper
                if (!isset($properties[$orderBy[0]])) {
                    continue;
                }

                // Map property
                $mapping = $properties[$orderBy[0]]->getMapping();
                if ($mapping) {
                    $propertyName = $mapping->getName($this->name);
                } else {
                    $propertyName = $orderBy[0];
                }

                $fluent->orderBy($propertyName)->{$orderBy[1]}();
            }
        }

        $result = $fluent->fetchAll();
        if (count($result) === 0) {
            return false;
        }

        return $this->createCollection($query->entityReflection->getName(), $result);
    }

    public function count(\UniMapper\Query\Count $query)
    {
        $fluent = $this->connection->select("*")->from("%n", $this->getResource($query->entityReflection));
        $this->setConditions($fluent, $this->translateConditions($query->entityReflection, $query->conditions));
        return $fluent->count();
    }

    /**
     * Insert
     *
     * @param \UniMapper\Query\Insert $query Query
     *
     * @return integer|null
     */
    public function insert(\UniMapper\Query\Insert $query)
    {
        $values = $this->entityToData($query->entity);
        if (empty($values)) {
            throw new MapperException("Entity has no mapped values!");
        }

        $this->connection->insert($this->getResource($query->entityReflection), $values)->execute();
        if ($query->returnPrimaryValue) {
            return $this->connection->getInsertId();
        }
    }

    /**
     * Update
     *
     * @param \UniMapper\Query\Update $query Query
     *
     * @return boolean
     */
    public function update(\UniMapper\Query\Update $query)
    {
        $values = $this->entityToData($query->entity);
        if (empty($values)) {
            return false;
        }

        $fluent = $this->connection->update(
            $this->getResource($query->entityReflection),
            $this->entityToData($query->entity)
        );
        $this->setConditions($fluent, $this->translateConditions($query->entityReflection, $query->conditions));
        return (bool) $fluent->execute();
    }

}