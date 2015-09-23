<?php

namespace pc\entity;

use yii\db\ActiveQueryInterface;

interface RepositoryInterface
{
    /**
     * Returns the primary key **name(s)** for this repository.
     *
     * Note that an array should be returned even when the record only has a single primary key.
     *
     * For the primary key **value** see [[getPrimaryKey()]] instead.
     *
     * @return string[] the primary key name(s) for this repository.
     */
    public static function primaryKey();

    /**
     * Returns a value indicating whether the given set of attributes represents the primary key for this model
     * @param array $keys the set of attributes to check
     * @return boolean whether the given set of attributes represents the primary key for this model
     */
    public static function isPrimaryKey($keys);
    
    /**
     * Returns the connection used by this repository.
     * @return mixed the database connection used by this repository.
     */
    public static function getDb();
    
    /**
     * Creates an [[ActiveQueryInterface]] instance for query purpose.
     *
     * The returned [[ActiveQueryInterface]] instance can be further customized by calling
     * methods defined in [[ActiveQueryInterface]] before `one()` or `all()` is called to return
     * populated ActiveRecord instances. For example,
     *
     * ```php
     * // find the customer whose ID is 1
     * $customer = Customer::find()->where(['id' => 1])->one();
     *
     * // find all active customers and order them by their age:
     * $customers = Customer::find()
     *     ->where(['status' => 1])
     *     ->orderBy('age')
     *     ->all();
     * ```
     *
     * This method is also called by [[BaseActiveRecord::hasOne()]] and [[BaseActiveRecord::hasMany()]] to
     * create a relational query.
     *
     * You may override this method to return a customized query. For example,
     *
     * ```php
     * class Customer extends ActiveRecord
     * {
     *     public static function find()
     *     {
     *         // use CustomerQuery instead of the default ActiveQuery
     *         return new CustomerQuery(get_called_class());
     *     }
     * }
     * ```
     *
     * The following code shows how to apply a default condition for all queries:
     *
     * ```php
     * class Customer extends ActiveRecord
     * {
     *     public static function find()
     *     {
     *         return parent::find()->where(['deleted' => false]);
     *     }
     * }
     *
     * // Use andWhere()/orWhere() to apply the default condition
     * // SELECT FROM customer WHERE `deleted`=:deleted AND age>30
     * $customers = Customer::find()->andWhere('age>30')->all();
     *
     * // Use where() to ignore the default condition
     * // SELECT FROM customer WHERE age>30
     * $customers = Customer::find()->where('age>30')->all();
     *
     * @return ActiveQueryInterface the newly created [[ActiveQueryInterface]] instance.
     */
    public static function find();

    /**
     * Returns a single active record model instance by a primary key or an array of column values.
     *
     * The method accepts:
     *
     *  - a scalar value (integer or string): query by a single primary key value and return the
     *    corresponding record (or null if not found).
     *  - a non-associative array: query by a list of primary key values and return the
     *    first record (or null if not found).
     *  - an associative array of name-value pairs: query by a set of attribute values and return a single record
     *    matching all of them (or null if not found). Note that `['id' => 1, 2]` is treated as a non-associative array.
     *
     * That this method will automatically call the `one()` method and return an [[ActiveRecordInterface|ActiveRecord]]
     * instance. For example,
     *
     * ```php
     * // find a single customer whose primary key value is 10
     * $customer = Customer::findOne(10);
     *
     * // the above code is equivalent to:
     * $customer = Customer::find()->where(['id' => 10])->one();
     *
     * // find the first customer whose age is 30 and whose status is 1
     * $customer = Customer::findOne(['age' => 30, 'status' => 1]);
     *
     * // the above code is equivalent to:
     * $customer = Customer::find()->where(['age' => 30, 'status' => 1])->one();
     * ```
     *
     * @param mixed $condition primary key value or a set of column values
     * @return static|null ActiveRecord instance matching the condition, or null if nothing matches.
     */
    public static function findOne($condition);

    /**
     * Returns a list of active record models that match the specified primary key value(s) or a set of column values.
     *
     * The method accepts:
     *
     *  - a scalar value (integer or string): query by a single primary key value and return an array containing the
     *    corresponding record (or an empty array if not found).
     *  - a non-associative array: query by a list of primary key values and return the
     *    corresponding records (or an empty array if none was found).
     *    Note that an empty condition will result in an empty result as it will be interpreted as a search for
     *    primary keys and not an empty `WHERE` condition.
     *  - an associative array of name-value pairs: query by a set of attribute values and return an array of records
     *    matching all of them (or an empty array if none was found). Note that `['id' => 1, 2]` is treated as
     *    a non-associative array.
     *
     * This method will automatically call the `all()` method and return an array of [[ActiveRecordInterface|ActiveRecord]]
     * instances. For example,
     *
     * ```php
     * // find the customers whose primary key value is 10
     * $customers = Customer::findAll(10);
     *
     * // the above code is equivalent to:
     * $customers = Customer::find()->where(['id' => 10])->all();
     *
     * // find the customers whose primary key value is 10, 11 or 12.
     * $customers = Customer::findAll([10, 11, 12]);
     *
     * // the above code is equivalent to:
     * $customers = Customer::find()->where(['id' => [10, 11, 12]])->all();
     *
     * // find customers whose age is 30 and whose status is 1
     * $customers = Customer::findAll(['age' => 30, 'status' => 1]);
     *
     * // the above code is equivalent to:
     * $customers = Customer::find()->where(['age' => 30, 'status' => 1])->all();
     * ```
     *
     * @param mixed $condition primary key value or a set of column values
     * @return array an array of ActiveRecord instance, or an empty array if nothing matches.
     */
    public static function findAll($condition);

    /**
     * Updates records using the provided attribute values and conditions.
     * For example, to change the status to be 1 for all customers whose status is 2:
     *
     * ```php
     * Customer::updateAll(['status' => 1], ['status' => '2']);
     * ```
     *
     * @param array $attributes attribute values (name-value pairs) to be saved for the record.
     * Unlike [[update()]] these are not going to be validated.
     * @param array $condition the condition that matches the records that should get updated.
     * Please refer to [[QueryInterface::where()]] on how to specify this parameter.
     * An empty condition will match all records.
     * @return integer the number of rows updated
     */
    public static function updateAll($attributes, $condition = null);

    /**
     * Deletes records using the provided conditions.
     * WARNING: If you do not specify any condition, this method will delete ALL rows in the table.
     *
     * For example, to delete all customers whose status is 3:
     *
     * ```php
     * Customer::deleteAll([status = 3]);
     * ```
     *
     * @param array $condition the condition that matches the records that should get deleted.
     * Please refer to [[QueryInterface::where()]] on how to specify this parameter.
     * An empty condition will match all records.
     * @return integer the number of rows deleted
     */
    public static function deleteAll($condition = null);
}