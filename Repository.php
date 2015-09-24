<?php

namespace pc\entity;

use Yii;
use yii\base\InvalidConfigException;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\db\StaleObjectException;
use yii\db\TableSchema;
use yii\helpers\Inflector;
use yii\helpers\StringHelper;

class Repository extends BaseRepository
{
    /**
     * The insert operation. This is mainly used when overriding [[transactions()]] to specify which operations are transactional.
     */
    const OP_INSERT = 0x01;
    /**
     * The update operation. This is mainly used when overriding [[transactions()]] to specify which operations are transactional.
     */
    const OP_UPDATE = 0x02;
    /**
     * The delete operation. This is mainly used when overriding [[transactions()]] to specify which operations are transactional.
     */
    const OP_DELETE = 0x04;
    /**
     * All three operations: insert, update, delete.
     * This is a shortcut of the expression: OP_INSERT | OP_UPDATE | OP_DELETE.
     */
    const OP_ALL = 0x07;

    /**
     * @inheritdoc
     */
    public function getDb()
    {
        return Yii::$app->getDb();
    }

    /**
     * @inheritdoc
     */
    public function primaryKey()
    {
        return $this->getTableSchema()->primaryKey;
    }

    /**
     * Returns the schema information of the DB table associated with this AR class.
     * @return TableSchema the schema information of the DB table associated with this AR class.
     * @throws InvalidConfigException if the table for the AR class does not exist.
     */
    public function getTableSchema()
    {
        $schema = $this->getDb()->getSchema()->getTableSchema($this->tableName());
        if ($schema !== null) {
            return $schema;
        } else {
            throw new InvalidConfigException("The table does not exist: " . $this->tableName());
        }
    }

    /**
     * @inheritdoc
     */
    public function find()
    {
        return Yii::createObject(ActiveQuery::className(), [$this]);
    }

    /**
     * Declares the name of the database table associated with this AR class.
     * By default this method returns the class name as the table name by calling [[Inflector::camel2id()]]
     * with prefix [[Connection::tablePrefix]]. For example if [[Connection::tablePrefix]] is 'tbl_',
     * 'Customer' becomes 'tbl_customer', and 'OrderItem' becomes 'tbl_order_item'. You may override this method
     * if the table is not named after this convention.
     * @return string the table name
     */
    public function tableName()
    {
        return '{{%' . Inflector::camel2id(StringHelper::basename(get_called_class()), '_') . '}}';
    }

    /**
     * @inheritdoc
     */
    public function updateAllCounters($counters, $condition = '', $params = [])
    {
        $n = 0;
        foreach ($counters as $name => $value) {
            $counters[$name] = new Expression("[[$name]]+:bp{$n}", [":bp{$n}" => $value]);
            $n++;
        }
        $command = $this->getDb()->createCommand();
        $command->update($this->tableName(), $counters, $condition, $params);

        return $command->execute();
    }

    /**
     * @inheritdoc
     */
    public function updateAll($attributes, $condition = '', $params = [])
    {
        $command = $this->getDb()->createCommand();
        $command->update($this->tableName(), $attributes, $condition, $params);

        return $command->execute();
    }

    /**
     * @inheritdoc
     */
    public function deleteAll($condition = '', $params = [])
    {
        $command = $this->getDb()->createCommand();
        $command->delete($this->tableName(), $condition, $params);

        return $command->execute();
    }

    /**
     * @inheritdoc
     */
    public function update($entity, $attributeNames = null)
    {
        if (!$entity->validate($attributeNames)) {
            Yii::info('Model not updated due to validation error.', __METHOD__);
            return false;
        }

        if (!$this->isTransactional($entity, self::OP_UPDATE)) {
            return $this->updateInternal($attributeNames);
        }

        $transaction = $this->getDb()->beginTransaction();
        try {
            $result = $this->updateInternal($entity, $attributeNames);
            if ($result === false) {
                $transaction->rollBack();
            } else {
                $transaction->commit();
            }
            return $result;
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * @inheritdoc
     */
    public function insert($entity, $attributes = null)
    {
        if (!$entity->validate($attributes)) {
            Yii::info('Model not inserted due to validation error.', __METHOD__);
            return false;
        }

        if (!$this->isTransactional($entity, self::OP_INSERT)) {
            return $this->insertInternal($entity, $attributes);
        }

        $transaction = $this->getDb()->beginTransaction();
        try {
            $result = $this->insertInternal($entity, $attributes);
            if ($result === false) {
                $transaction->rollBack();
            } else {
                $transaction->commit();
            }
            return $result;
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Inserts an ActiveRecord into DB without considering transaction.
     * @param Entity $entity
     * @param array $attributes list of attributes that need to be saved. Defaults to null,
     * meaning all attributes that are loaded from DB will be saved.
     * @return boolean whether the record is inserted successfully.
     */
    protected function insertInternal($entity, $attributes = null)
    {
        if (!$this->beforeSave($entity, true)) {
            return false;
        }
        $values = $entity->getDirtyAttributes($attributes);
        if (($primaryKeys = static::getDb()->schema->insert($this->tableName(), $values)) === false) {
            return false;
        }
        foreach ($primaryKeys as $name => $value) {
            $id = $this->getTableSchema()->columns[$name]->phpTypecast($value);
            $entity->setAttribute($name, $id);
            $values[$name] = $id;
        }

        $changedAttributes = array_fill_keys(array_keys($values), null);
        $entity->setOldAttributes($values);
        $this->afterSave($entity, true, $changedAttributes);

        return true;
    }


    /**
     * Deletes the table row corresponding to this active record.
     *
     * This method performs the following steps in order:
     *
     * 1. call [[beforeDelete()]]. If the method returns false, it will skip the
     *    rest of the steps;
     * 2. delete the record from the database;
     * 3. call [[afterDelete()]].
     *
     * In the above step 1 and 3, events named [[EVENT_BEFORE_DELETE]] and [[EVENT_AFTER_DELETE]]
     * will be raised by the corresponding methods.
     *
     * @param Entity $entity
     * @return integer|false the number of rows deleted, or false if the deletion is unsuccessful for some reason.
     * Note that it is possible the number of rows deleted is 0, even though the deletion execution is successful.
     * @throws StaleObjectException if [[optimisticLock|optimistic locking]] is enabled and the data
     * being deleted is outdated.
     * @throws \Exception in case delete failed.
     */
    public function delete($entity)
    {
        if (!$this->isTransactional($entity,self::OP_DELETE)) {
            return $this->deleteInternal($entity);
        }

        $transaction = static::getDb()->beginTransaction();
        try {
            $result = $this->deleteInternal($entity);
            if ($result === false) {
                $transaction->rollBack();
            } else {
                $transaction->commit();
            }
            return $result;
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Deletes an ActiveRecord without considering transaction.
     * @param Entity $entity
     * @return integer|false the number of rows deleted, or false if the deletion is unsuccessful for some reason.
     * Note that it is possible the number of rows deleted is 0, even though the deletion execution is successful.
     * @throws StaleObjectException
     */
    protected function deleteInternal($entity)
    {
        if (!$this->beforeDelete($entity)) {
            return false;
        }

        // we do not check the return value of deleteAll() because it's possible
        // the record is already deleted in the database and thus the method will return 0
        $condition = $this->getOldPrimaryKey(true);
        $lock = $this->optimisticLock();
        if ($lock !== null) {
            $condition[$lock] = $this->$lock;
        }
        $result = $this->deleteAll($condition);
        if ($lock !== null && !$result) {
            throw new StaleObjectException('The object being deleted is outdated.');
        }
        $entity->setOldAttributes(null);
        $this->afterDelete($entity);

        return $result;
    }

    /**
     * Returns a value indicating whether the specified operation is transactional in the current [[scenario]].
     * @param Entity $entity
     * @param integer $operation the operation to check. Possible values are [[OP_INSERT]], [[OP_UPDATE]] and [[OP_DELETE]].
     * @return boolean whether the specified operation is transactional in the current [[scenario]].
     */
    public function isTransactional($entity, $operation)
    {
        $scenario = $entity->getScenario();
        $transactions = $this->transactions();

        return isset($transactions[$scenario]) && ($transactions[$scenario] & $operation);
    }

    /**
     * Declares which DB operations should be performed within a transaction in different scenarios.
     * The supported DB operations are: [[OP_INSERT]], [[OP_UPDATE]] and [[OP_DELETE]],
     * which correspond to the [[insert()]], [[update()]] and [[delete()]] methods, respectively.
     * By default, these methods are NOT enclosed in a DB transaction.
     *
     * In some scenarios, to ensure data consistency, you may want to enclose some or all of them
     * in transactions. You can do so by overriding this method and returning the operations
     * that need to be transactional. For example,
     *
     * ```php
     * return [
     *     'admin' => self::OP_INSERT,
     *     'api' => self::OP_INSERT | self::OP_UPDATE | self::OP_DELETE,
     *     // the above is equivalent to the following:
     *     // 'api' => self::OP_ALL,
     *
     * ];
     * ```
     *
     * The above declaration specifies that in the "admin" scenario, the insert operation ([[insert()]])
     * should be done in a transaction; and in the "api" scenario, all the operations should be done
     * in a transaction.
     *
     * @return array the declarations of transactional operations. The array keys are scenarios names,
     * and the array values are the corresponding transaction operations.
     */
    public function transactions()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function equals($source, $target)
    {
        if ($source->getIsNewRecord() || $target->getIsNewRecord()) {
            return false;
        }
        /** @var RepositoryInterface $sourceRepo */
        $sourceRepo = $this->entityManager->getRepository($source::className());
        /** @var RepositoryInterface $sourceRepo */
        $targetRepo = $this->entityManager->getRepository($target::className());

        return get_class($sourceRepo) === get_class($targetRepo) && $sourceRepo->getPrimaryKey($source) === $targetRepo->getPrimaryKey($target);
    }
}