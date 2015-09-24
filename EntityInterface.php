<?php

namespace pc\entity;

interface EntityInterface
{
    /**
     * Returns the list of all attribute names of the record.
     * @return array list of attribute names.
     */
    public function attributes();

    /**
     * Returns the named attribute value.
     * If this record is the result of a query and the attribute is not loaded,
     * null will be returned.
     * @param string $name the attribute name
     * @return mixed the attribute value. Null if the attribute is not set or does not exist.
     * @see hasAttribute()
     */
    public function getAttribute($name);

    /**
     * Sets the named attribute value.
     * @param string $name the attribute name.
     * @param mixed $value the attribute value.
     * @see hasAttribute()
     */
    public function setAttribute($name, $value);

    /**
     * Returns a value indicating whether the record has an attribute with the specified name.
     * @param string $name the name of the attribute
     * @return boolean whether the record has an attribute with the specified name.
     */
    public function hasAttribute($name);

    /**
     * Returns a value indicating whether the current record is new (not saved in the database).
     * @return boolean whether the record is new and should be inserted when calling [[save()]].
     */
    public function getIsNewRecord();
}