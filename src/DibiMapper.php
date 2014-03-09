<?php

namespace UniMapper\Mapper;

use UniMapper\Query\Object\Order,
    UniMapper\Reflection\EntityReflection,
    UniMapper\Exceptions\MapperException;

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
            return $this->connection->query($query->query);
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
    protected function modifyResultValue($value)
    {
        if ($value instanceof \DibiDateTime) {
            return new \DateTime($value);
        }
        return $value;
    }

    /**
     * Get mapped conditions from query
     *
     * @param \DibiFluent    $fluent Dibi fluent
     * @param \UniMapper\Query $query  Query object
     *
     * @return \DibiFluent
     */
    protected function getConditions(\DibiFluent $fluent, EntityReflection $entityReflection, array $conditions)
    {
        $properties = $entityReflection->getProperties($this->name);
        foreach ($conditions as $condition) {

            list($propertyName, $operator, $value, $joiner) = $condition;

            // Skip unrelated conditions
            if (!isset($properties[$propertyName])) {
                continue;
            }

            // Apply defined mapping from entity
            $mapping = $properties[$propertyName]->getMapping();
            if ($mapping) {
                $mappedPropertyName = $mapping->getName($this->name);
                if ($mappedPropertyName) {
                    $propertyName = $mappedPropertyName;
                }
            }

            // Convert data type definition to dibi modificator
            $type = gettype($value);
            if ($type === "object") {
                $type = get_class($type);
            }
            if (!isset($this->modificators[$type])) {
                throw new MapperException(
                    "Value type " . $type . " is not supported"
                );
            }

            // Get operator
            if ($operator === "COMPARE") {
                if ($this->connection->getDriver() instanceof \DibiPostgreDriver) {
                    $operator = "ILIKE";
                } elseif ($this->connection->getDriver() instanceof \DibiMySqlDriver) {
                    $operator = "LIKE";
                }
            }

            // Add condition
            if ($joiner === "AND") {
                $fluent->where(
                    "%n %sql " . $this->modificators[$type],
                    $propertyName,
                    $operator,
                    $value
                );
            } else {
                $fluent->or(
                    "%n %sql " . $this->modificators[$type],
                    $propertyName,
                    $operator,
                    $value
                );
            }
        }
        return $fluent;
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

        return $this->getConditions(
            $this->connection->delete($this->getResource($query->entityReflection)),
            $query->entityReflection,
            $query->conditions
        )->execute();
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
        $this->getConditions($fluent, $query->entityReflection, array($condition));

        $result = $fluent->fetch();

        $entityClass = $query->entityReflection->getName();
        if ($result) {

            $entity = new $entityClass;
            $entity->importData($result, $this->name, $this->modifyResultValue());
            return $entity;
        }
        return false;
    }

    /**
     * FindAll
     *
     * @param \UniMapper\Query\FindAll $query FindAll Query
     *
     * @return mixed
     */
    public function findAll(\UniMapper\Query\FindAll $query)
    {
        $selection = $this->getSelection($query->entityReflection, $query->selection);

        $fluent = $this->connection
            ->select("[" . implode("],[", $selection) . "]")
            ->from("%n", $this->getResource($query->entityReflection));

        $this->getConditions($fluent, $query->entityReflection, $query->conditions);

        if ($query->limit > 0) {
            $fluent->limit("%i", $query->limit);
        }

        if ($query->offset > 0) {
            $fluent->offset("%i", $query->offset);
        }

        if (count($query->orders) > 0) {

            foreach ($query->orders as $order) {

                if (!$order instanceof Order) {
                    throw new MapperException("Order collection must contain only \UniMapper\Query\Object\Order objects!");
                }

                // Map property name to defined mapping definition
                $properties = $query->entityReflection->getProperties($this->name);

                // Skip properties not related to this mapper
                if (!isset($properties[$order->propertyName])) {
                    continue;
                }

                // Map property
                $mapping = $properties[$order->propertyName]->getMapping();
                if ($mapping) {
                    $propertyName = $mapping->getName($this->name);
                } else {
                    $propertyName = $order->propertyName;
                }

                $fluent->orderBy($order->propertyName)
                    ->asc($order->asc)
                    ->desc($order->desc);
            }
        }

        $result = $fluent->fetchAll();
        if (count($result === 0)) {
            return false;
        }

        return $this->createCollection($query->entityReflection->getName(), $result, $this->modifyResultValue());
    }

    public function count(\UniMapper\Query\Count $query)
    {
        $fluent = $this->connection->select("*")->from("%n", $this->getResource($query->entityReflection));
        $this->getConditions($fluent, $query->entityReflection, $query->conditions);
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
        return (bool) $this->getConditions($fluent, $query->entityReflection, $query->conditions)->execute();
    }

}