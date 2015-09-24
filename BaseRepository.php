<?php

namespace pc\entity;

use Yii;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\ModelEvent;
use yii\db\ActiveQueryInterface;
use yii\db\AfterSaveEvent;
use yii\db\StaleObjectException;
use yii\helpers\ArrayHelper;

abstract class BaseRepository extends Component implements RepositoryInterface
{
    /**
     * @event Event an event that is triggered after the record is created and populated with query result.
     */
    const EVENT_AFTER_FIND = 'afterFind';
    /**
     * @event ModelEvent an event that is triggered before inserting a record.
     * You may set [[ModelEvent::isValid]] to be false to stop the insertion.
     */
    const EVENT_BEFORE_INSERT = 'beforeInsert';
    /**
     * @event Event an event that is triggered after a record is inserted.
     */
    const EVENT_AFTER_INSERT = 'afterInsert';
    /**
     * @event ModelEvent an event that is triggered before updating a record.
     * You may set [[ModelEvent::isValid]] to be false to stop the update.
     */
    const EVENT_BEFORE_UPDATE = 'beforeUpdate';
    /**
     * @event Event an event that is triggered after a record is updated.
     */
    const EVENT_AFTER_UPDATE = 'afterUpdate';
    /**
     * @event ModelEvent an event that is triggered before deleting a record.
     * You may set [[ModelEvent::isValid]] to be false to stop the deletion.
     */
    const EVENT_BEFORE_DELETE = 'beforeDelete';
    /**
     * @event Event an event that is triggered after a record is deleted.
     */
    const EVENT_AFTER_DELETE = 'afterDelete';

    /**
     * @inheritdoc
     */
    public function findOne($condition)
    {
        return $this->findByCondition($condition)->one();
    }

    /**
     * @inheritdoc
     */
    public function findAll($condition)
    {
        return $this->findByCondition($condition)->all();
    }

    /**
     * Finds Entity instance(s) by the given condition.
     * This method is internally called by [[findOne()]] and [[findAll()]].
     * @param mixed $condition please refer to [[findOne()]] for the explanation of this parameter
     * @return ActiveQueryInterface the newly created [[ActiveQueryInterface|ActiveQuery]] instance.
     * @throws InvalidConfigException if there is no primary key defined
     * @internal
     */
    protected function findByCondition($condition)
    {
        $query = $this->find();

        if (!ArrayHelper::isAssociative($condition)) {
            // query by primary key
            $primaryKey = $this->primaryKey();
            if (isset($primaryKey[0])) {
                $condition = [$primaryKey[0] => $condition];
            } else {
                throw new InvalidConfigException('"' . get_called_class() . '" must have a primary key.');
            }
        }

        return $query->andWhere($condition);
    }

    /**
     * @inheritdoc
     */
    public static function populateRecord($record, $row)
    {
        $columns = array_flip($record->attributes());
        foreach ($row as $name => $value) {
            if (isset($columns[$name])) {
                $record->setAttribute($name, $value);
            } elseif ($record->canSetProperty($name)) {
                $record->$name = $value;
            }
        }
        $record->setOldAttributes($record->getAttributes());
    }

    /**
     * @inheritdoc
     */
    public function instantiate($row)
    {
        return new static;
    }

    /**
     * @inheritdoc
     */
    public function isPrimaryKey($keys)
    {
        $pks = $this->primaryKey();
        if (count($keys) === count($pks)) {
            return count(array_intersect($keys, $pks)) === count($pks);
        } else {
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function save($entity, $attributeNames = null)
    {
        if ($entity->getIsNewRecord()) {
            return $this->insert($entity, $attributeNames);
        } else {
            return $this->update($entity, $attributeNames) !== false;
        }
    }

    /**
     * @inheritdoc
     */
    public function delete($entity)
    {
        $result = false;
        if ($this->beforeDelete($entity)) {
            // we do not check the return value of deleteAll() because it's possible
            // the record is already deleted in the database and thus the method will return 0
            $condition = $this->getOldPrimaryKey($entity, true);
            $lock = $this->optimisticLock();
            if ($lock !== null) {
                $condition[$lock] = $entity->$lock;
            }
            $result = $this->deleteAll($condition);
            if ($lock !== null && !$result) {
                throw new StaleObjectException('The object being deleted is outdated.');
            }
            $entity->setOldAttributes(null);
            $this->afterDelete($entity);
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function update($entity, $attributeNames = null)
    {
        if (!$entity->validate($attributeNames)) {
            return false;
        }
        return $this->updateInternal($entity, $attributeNames);
    }

    /**
     * @inheritdoc
     */
    public function updateAttributes($entity, $attributes)
    {
        $attrs = [];
        foreach ($attributes as $name => $value) {
            if (is_int($name)) {
                $attrs[] = $value;
            } else {
                $this->$name = $value;
                $attrs[] = $name;
            }
        }

        $values = $entity->getDirtyAttributes($attrs);
        if (empty($values)) {
            return 0;
        }

        $rows = $this->updateAll($values, $this->getOldPrimaryKey($entity, true));

        foreach ($values as $name => $value) {
            $entity->setOldAttribute($name, $entity->getAttribute($name));
        }

        return $rows;
    }

    /**
     * @inheritdoc
     */
    public function getOldPrimaryKey($entity, $asArray = false)
    {
        $keys = $this->primaryKey();
        if (empty($keys)) {
            throw new Exception(get_class($this) . ' does not have a primary key. You should either define a primary key for the corresponding table or override the primaryKey() method.');
        }
        if (!$asArray && count($keys) === 1) {
            return $entity->getOldAttribute($keys[0]);
        } else {
            $values = [];
            foreach ($keys as $name) {
                $values[$name] = $entity->getOldAttribute($name);
            }

            return $values;
        }
    }

    /**
     * @see update()
     * @param Entity $entity
     * @param array $attributes attributes to update
     * @return integer number of rows updated
     * @throws StaleObjectException
     */
    protected function updateInternal($entity, $attributes = null)
    {
        if (!$this->beforeSave($entity, false)) {
            return false;
        }
        $values = $entity->getDirtyAttributes($attributes);
        if (empty($values)) {
            $this->afterSave($entity, false, $values);
            return 0;
        }
        $condition = $this->getOldPrimaryKey($entity, true);
        $lock = $this->optimisticLock();
        if ($lock !== null) {
            $values[$lock] = $entity->$lock + 1;
            $condition[$lock] = $entity->$lock;
        }
        // We do not check the return value of updateAll() because it's possible
        // that the UPDATE statement doesn't change anything and thus returns 0.
        $rows = $this->updateAll($values, $condition);

        if ($lock !== null && !$rows) {
            throw new StaleObjectException('The object being updated is outdated.');
        }

        if (isset($values[$lock])) {
            $entity->$lock = $values[$lock];
        }

        $changedAttributes = [];
        foreach ($values as $name => $value) {
            $changedAttributes[$name] = $entity->getOldAttribute($name);
            $entity->setOldAttribute($name, $value);
        }
        $this->afterSave($entity, false, $changedAttributes);

        return $rows;
    }

    /**
     * @inheritdoc
     */
    public function updateCounters($entity, $counters)
    {
        if ($this->updateAllCounters($counters, $this->getOldPrimaryKey($entity, true)) > 0) {
            foreach ($counters as $name => $value) {
                if ($entity->getAttribute($name) > 0) {
                    $value += $value;
                }
                $entity->setAttribute($name, $value);
                $entity->setOldAttribute($name, $entity->getAttribute($name));
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function getPrimaryKey($entity, $asArray = false)
    {
        $keys = $this->primaryKey();
        if (!$asArray && count($keys) === 1) {
            return $entity->getAttribute($keys[0]);
        } else {
            $values = [];
            foreach ($keys as $name) {
                $values[$name] = $entity->getOldAttribute($keys[0]);
            }

            return $values;
        }
    }

    /**
     * Returns the name of the column that stores the lock version for implementing optimistic locking.
     *
     * Optimistic locking allows multiple users to access the same record for edits and avoids
     * potential conflicts. In case when a user attempts to save the record upon some staled data
     * (because another user has modified the data), a [[StaleObjectException]] exception will be thrown,
     * and the update or deletion is skipped.
     *
     * Optimistic locking is only supported by [[update()]] and [[delete()]].
     *
     * To use Optimistic locking:
     *
     * 1. Create a column to store the version number of each row. The column type should be `BIGINT DEFAULT 0`.
     *    Override this method to return the name of this column.
     * 2. Add a `required` validation rule for the version column to ensure the version value is submitted.
     * 3. In the Web form that collects the user input, add a hidden field that stores
     *    the lock version of the recording being updated.
     * 4. In the controller action that does the data updating, try to catch the [[StaleObjectException]]
     *    and implement necessary business logic (e.g. merging the changes, prompting stated data)
     *    to resolve the conflict.
     *
     * @return string the column name that stores the lock version of a table row.
     * If null is returned (default implemented), optimistic locking will not be supported.
     */
    public function optimisticLock()
    {
        return null;
    }

    /**
     * This method is called at the beginning of inserting or updating a record.
     * The default implementation will trigger an [[EVENT_BEFORE_INSERT]] event when `$insert` is true,
     * or an [[EVENT_BEFORE_UPDATE]] event if `$insert` is false.
     * When overriding this method, make sure you call the parent implementation like the following:
     *
     * ```php
     * public function beforeSave($insert)
     * {
     *     if (parent::beforeSave($insert)) {
     *         // ...custom code here...
     *         return true;
     *     } else {
     *         return false;
     *     }
     * }
     * ```
     *
     * @param Entity $entity
     * @param boolean $insert whether this method called while inserting a record.
     * If false, it means the method is called while updating a record.
     * @return boolean whether the insertion or updating should continue.
     * If false, the insertion or updating will be cancelled.
     */
    public function beforeSave($entity, $insert)
    {
        $event = new ModelEvent();
        $this->trigger($insert ? self::EVENT_BEFORE_INSERT : self::EVENT_BEFORE_UPDATE, $event);

        return $event->isValid;
    }

    /**
     * This method is called at the end of inserting or updating a record.
     * The default implementation will trigger an [[EVENT_AFTER_INSERT]] event when `$insert` is true,
     * or an [[EVENT_AFTER_UPDATE]] event if `$insert` is false. The event class used is [[AfterSaveEvent]].
     * When overriding this method, make sure you call the parent implementation so that
     * the event is triggered.
     * @param Entity $entity
     * @param boolean $insert whether this method called while inserting a record.
     * If false, it means the method is called while updating a record.
     * @param array $changedAttributes The old values of attributes that had changed and were saved.
     * You can use this parameter to take action based on the changes made for example send an email
     * when the password had changed or implement audit trail that tracks all the changes.
     * `$changedAttributes` gives you the old attribute values while the active record (`$this`) has
     * already the new, updated values.
     */
    public function afterSave($entity, $insert, $changedAttributes)
    {
        $this->trigger($insert ? self::EVENT_AFTER_INSERT : self::EVENT_AFTER_UPDATE, new AfterSaveEvent([
            'changedAttributes' => $changedAttributes
        ]));
    }

    /**
     * This method is invoked before deleting a record.
     * The default implementation raises the [[EVENT_BEFORE_DELETE]] event.
     * When overriding this method, make sure you call the parent implementation like the following:
     *
     * ```php
     * public function beforeDelete()
     * {
     *     if (parent::beforeDelete()) {
     *         // ...custom code here...
     *         return true;
     *     } else {
     *         return false;
     *     }
     * }
     * ```
     *
     * @param Entity $entity
     * @return boolean whether the record should be deleted. Defaults to true.
     */
    public function beforeDelete($entity)
    {
        $event = new ModelEvent;
        $this->trigger(self::EVENT_BEFORE_DELETE, $event);

        return $event->isValid;
    }

    /**
     * This method is invoked after deleting a record.
     * The default implementation raises the [[EVENT_AFTER_DELETE]] event.
     * You may override this method to do postprocessing after the record is deleted.
     * Make sure you call the parent implementation so that the event is raised properly.
     * @param Entity $entity
     */
    public function afterDelete($entity)
    {
        $this->trigger(self::EVENT_AFTER_DELETE);
    }

    /**
     * @inheritdoc
     */
    public function equals($source, $target)
    {
        if ($source->getIsNewRecord() || $target->getIsNewRecord()) {
            return false;
        }

        return get_class($source) === get_class($target) && $this->getPrimaryKey($source) === $this->getPrimaryKey($target);
    }
}