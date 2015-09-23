<?php

namespace pc\entity;

use Yii;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\db\ActiveQueryInterface;
use yii\helpers\ArrayHelper;

abstract class BaseRepository implements RepositoryInterface
{
    /**
     * @inheritdoc
     * @return static|null Entity instance matching the condition, or `null` if nothing matches.
     */
    public static function findOne($condition)
    {
        return static::findByCondition($condition)->one();
    }

    /**
     * @inheritdoc
     * @return static[] an array of Entity instances, or an empty array if nothing matches.
     */
    public static function findAll($condition)
    {
        return static::findByCondition($condition)->all();
    }

    /**
     * Finds Entity instance(s) by the given condition.
     * This method is internally called by [[findOne()]] and [[findAll()]].
     * @param mixed $condition please refer to [[findOne()]] for the explanation of this parameter
     * @return ActiveQueryInterface the newly created [[ActiveQueryInterface|ActiveQuery]] instance.
     * @throws InvalidConfigException if there is no primary key defined
     * @internal
     */
    protected static function findByCondition($condition)
    {
        $query = static::find();

        if (!ArrayHelper::isAssociative($condition)) {
            // query by primary key
            $primaryKey = static::primaryKey();
            if (isset($primaryKey[0])) {
                $condition = [$primaryKey[0] => $condition];
            } else {
                throw new InvalidConfigException('"' . get_called_class() . '" must have a primary key.');
            }
        }

        return $query->andWhere($condition);
    }

    /**
     * Updates the whole table using the provided attribute values and conditions.
     * For example, to change the status to be 1 for all customers whose status is 2:
     *
     * ```php
     * Customer::updateAll(['status' => 1], 'status = 2');
     * ```
     *
     * @param array $attributes attribute values (name-value pairs) to be saved into the table
     * @param string|array $condition the conditions that will be put in the WHERE part of the UPDATE SQL.
     * Please refer to [[Query::where()]] on how to specify this parameter.
     * @return integer the number of rows updated
     * @throws NotSupportedException if not overridden
     */
    public static function updateAll($attributes, $condition = '')
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported.');
    }

    /**
     * Updates the whole table using the provided counter changes and conditions.
     * For example, to increment all customers' age by 1,
     *
     * ```php
     * Customer::updateAllCounters(['age' => 1]);
     * ```
     *
     * @param array $counters the counters to be updated (attribute name => increment value).
     * Use negative values if you want to decrement the counters.
     * @param string|array $condition the conditions that will be put in the WHERE part of the UPDATE SQL.
     * Please refer to [[Query::where()]] on how to specify this parameter.
     * @return integer the number of rows updated
     * @throws NotSupportedException if not overrided
     */
    public static function updateAllCounters($counters, $condition = '')
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported.');
    }

    /**
     * Deletes rows in the table using the provided conditions.
     * WARNING: If you do not specify any condition, this method will delete ALL rows in the table.
     *
     * For example, to delete all customers whose status is 3:
     *
     * ```php
     * Customer::deleteAll('status = 3');
     * ```
     *
     * @param string|array $condition the conditions that will be put in the WHERE part of the DELETE SQL.
     * Please refer to [[Query::where()]] on how to specify this parameter.
     * @param array $params the parameters (name => value) to be bound to the query.
     * @return integer the number of rows deleted
     * @throws NotSupportedException if not overrided
     */
    public static function deleteAll($condition = '', $params = [])
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported.');
    }

    /**
     * Populates an active record object using a row of data from the database/storage.
     *
     * This is an internal method meant to be called to create active record objects after
     * fetching data from the database. It is mainly used by [[ActiveQuery]] to populate
     * the query results into active records.
     *
     * When calling this method manually you should call [[afterFind()]] on the created
     * record to trigger the [[EVENT_AFTER_FIND|afterFind Event]].
     *
     * @param Entity $record the record to be populated. In most cases this will be an instance
     * created by [[instantiate()]] beforehand.
     * @param array $row attribute values (name => value)
     */
    public static function populateRecord($record, $row)
    {
        $columns = array_flip($record->attributes());
        foreach ($row as $name => $value) {
            if (isset($columns[$name])) {
                $record->_attributes[$name] = $value;
            } elseif ($record->canSetProperty($name)) {
                $record->$name = $value;
            }
        }
        $record->_oldAttributes = $record->_attributes;
    }

    /**
     * Creates an active record instance.
     *
     * This method is called together with [[populateRecord()]] by [[ActiveQuery]].
     * It is not meant to be used for creating new records directly.
     *
     * You may override this method if the instance being created
     * depends on the row data to be populated into the record.
     * For example, by creating a record based on the value of a column,
     * you may implement the so-called single-table inheritance mapping.
     * @param array $row row data to be populated into the record.
     * @return static the newly created active record
     */
    public static function instantiate($row)
    {
        return new static;
    }

    /**
     * Returns a value indicating whether the given set of attributes represents the primary key for this model
     * @param array $keys the set of attributes to check
     * @return boolean whether the given set of attributes represents the primary key for this model
     */
    public static function isPrimaryKey($keys)
    {
        $pks = static::primaryKey();
        if (count($keys) === count($pks)) {
            return count(array_intersect($keys, $pks)) === count($pks);
        } else {
            return false;
        }
    }
}

use yii\db\Connection;
use yii\db\ActiveQuery;
use yii\db\TableSchema;
use yii\helpers\Inflector;
use yii\helpers\StringHelper;

class Repository extends BaseRepository
{
    /**
     * Returns the database connection used by this AR class.
     * By default, the "db" application component is used as the database connection.
     * You may override this method if you want to use a different database connection.
     * @return Connection the database connection used by this AR class.
     */
    public static function getDb()
    {
        return Yii::$app->getDb();
    }

    /**
     * Returns the primary key name(s) for this AR class.
     * The default implementation will return the primary key(s) as declared
     * in the DB table that is associated with this AR class.
     *
     * If the DB table does not declare any primary key, you should override
     * this method to return the attributes that you want to use as primary keys
     * for this AR class.
     *
     * Note that an array should be returned even for a table with single primary key.
     *
     * @return string[] the primary keys of the associated database table.
     */
    public static function primaryKey()
    {
        return static::getTableSchema()->primaryKey;
    }

    /**
     * Returns the schema information of the DB table associated with this AR class.
     * @return TableSchema the schema information of the DB table associated with this AR class.
     * @throws InvalidConfigException if the table for the AR class does not exist.
     */
    public static function getTableSchema()
    {
        $schema = static::getDb()->getSchema()->getTableSchema(static::tableName());
        if ($schema !== null) {
            return $schema;
        } else {
            throw new InvalidConfigException("The table does not exist: " . static::tableName());
        }
    }

    /**
     * @inheritdoc
     * @return ActiveQuery the newly created [[ActiveQuery]] instance.
     */
    public static function find()
    {
        return Yii::createObject(ActiveQuery::className(), [get_called_class()]);
    }

    /**
     * Declares the name of the database table associated with this AR class.
     * By default this method returns the class name as the table name by calling [[Inflector::camel2id()]]
     * with prefix [[Connection::tablePrefix]]. For example if [[Connection::tablePrefix]] is 'tbl_',
     * 'Customer' becomes 'tbl_customer', and 'OrderItem' becomes 'tbl_order_item'. You may override this method
     * if the table is not named after this convention.
     * @return string the table name
     */
    public static function tableName()
    {
        return '{{%' . Inflector::camel2id(StringHelper::basename(get_called_class()), '_') . '}}';
    }
}