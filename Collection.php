<?php

require_once 'Jsonable.php';
require_once 'Arrayable.php';



class Collection implements ArrayAccess, Arrayable, Countable, IteratorAggregate, Jsonable, JsonSerializable
{
    protected array $items = [];

    public function __construct($items = [])
    {
        $this->items = $this->getArrayableItems($items);
    }

    protected function getArrayableItems($items)
    {
        if (is_array($items)) {
            return $items;
        }

        if ($items instanceof self) {
            return $items->all();
        }

        return (array) $items;
    }

    protected function operatorForWhere($key, $operator, $value = null)
    {
        if (func_num_args() === 2) {
            $value = $operator;

            $operator = '=';
        }

        return function ($item) use ($key, $operator, $value) {
            $retrieved = $this->get($item, $key);

            $strings = array_filter([$retrieved, $value], static function ($value): bool {
                return is_string($value) || (is_object($value) && method_exists($value, '__toString'));
            });

            if (count($strings) < 2 && count(array_filter([$retrieved, $value], 'is_object')) == 1) {
                return in_array($operator, ['!=', '<>', '!==']);
            }

            switch ($operator) {
                default:
                case '=':
                case '==':  return $retrieved == $value;
                case '!=':
                case '<>':  return $retrieved != $value;
                case '<':   return $retrieved < $value;
                case '>':   return $retrieved > $value;
                case '<=':  return $retrieved <= $value;
                case '>=':  return $retrieved >= $value;
                case '===': return $retrieved === $value;
                case '!==': return $retrieved !== $value;
            }
        };
    }

    public function offsetSet($key, $value)
    {
        if (is_null($key)) {
            $this->items[] = $value;
        } else {
            $this->items[$key] = $value;
        }
    }

    public function offsetExists($key)
    {
        return array_key_exists($key, $this->items);
    }

    public function offsetGet($key)
    {
        return $this->items[$key];
    }

    public function offsetUnset($key)
    {
        unset($this->items[$key]);
    }

    public function getIterator()
    {
        //dd('dd');
        return new ArrayIterator($this->items);
    }

    public function toJson($options = 0)
    {
        return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR | $options);
    }

    public function jsonSerialize()
    {
        return array_map(static function ($value) {
            if ($value instanceof JsonSerializable) {
                return $value->jsonSerialize();
            }

            if ($value instanceof Jsonable) {
                return json_decode($value->toJson(), true, 512, JSON_THROW_ON_ERROR);
            }

            if ($value instanceof Arrayable) {
                return $value->toArray();
            }

            return $value;
        }, $this->items);
    }

    public function count()
    {
        return count($this->items);
    }

    public  function get($array, $key, $default = null)
    {

        if (is_null($key)) {
            return $array;
        }

        $key = is_array($key) ? $key : explode('.', $key);

        while (! is_null($segment = array_shift($key))) {
            if ($segment === '*') {
                if ($array instanceof self) {
                    $array = $array->all();
                } elseif (! is_array($array)) {
                    return value($default);
                }

                $result = Arr::pluck($array, $key);

                return in_array('*', $key) ? Arr::collapse($result) : $result;
            }

            if ($this->accessible($array) && $this->exists($array, $segment)) {
                $array = $array[$segment];
            } elseif (is_object($array) && isset($array->{$segment})) {
                $array = $array->{$segment};
            } else {
                return value($default);
            }
        }

        return $array;
    }

    public  function accessible($value)
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }

    public  function exists($array, $key)
    {
        if ($array instanceof ArrayAccess) {
            return $array->offsetExists($key);
        }
        return array_key_exists($key, $array);
    }

    public function reduce(callable $callback, $initial = null)
    {
        return array_reduce($this->items, $callback, $initial);
    }

    public function all()
    {
        return $this->items;
    }

    public function avg($callback = null)
    {
        if ($count = $this->count()) {
            return $this->sum($callback) / $count;
        }
        return 0;
    }

    public function sum()
    {
        return array_sum($this->items);
    }

     public function Where($key,$operator,$value=null)
    {

        $array= array_filter($this->items,$this->operatorForWhere(...func_get_args()) , ARRAY_FILTER_USE_BOTH);
        return new static($array);
    }

    public function WhereIn($Key,$values,$strict = false)
    {

        $array= array_filter($this->items, function ($item) use ($Key, $values, $strict) {
            return in_array($this->get($item, $Key), $values, $strict);
        } , ARRAY_FILTER_USE_BOTH);
        return new static($array);
    }

    public function whereNotIn($Key,$values,$strict = false)
    {
        $array= array_filter($this->items, function ($item) use ($Key, $values, $strict) {
            return !in_array($this->get($item, $Key), $values, $strict);
        } , ARRAY_FILTER_USE_BOTH);
        return new static($array);
    }

    public function chunk($size)
    {
        if ($size <= 0) {
            return new static;
        }

        $chunks = [];

        foreach (array_chunk($this->items, $size, true) as $chunk) {
            $chunks[] = new static($chunk);
        }

        return new static($chunks);
    }

    public function first( callable $callback = null, $default = null)
    {

        if (is_null($callback)) {
            if (empty($this->items)) {
                return value($default);
            }

            foreach ($this->items as $item) {
                return $item;
            }
        }

        foreach ($this->items as $key => $value) {
            if (call_user_func($callback, $value, $key)) {
                return $value;
            }
        }

        return value($default);
    }

    public function firstWhere($key, $operator, $value = null)
    {
        return $this->first($this->operatorForWhere(...func_get_args()));
    }

    public function sort(callable $callback = null)
    {
        $items = $this->items;

        $callback
            ? uasort($items, $callback)
            : asort($items);

        return new static($items);
    }

    public function sortBy( $callback , $options = SORT_REGULAR, $descending = false)
    {
        //$items = $this->items;
        $results = [];

        if(is_string($callback) && !is_callable($callback)){
            $callback=function ($item) use ($callback) {
                return $this->get($item, $callback);
            };
        }

        foreach ($this->items as $key => $value) {
            $results[$key] = $callback($value, $key);
        }


        $descending ? arsort($results, $options)
            : asort($results, $options);

        foreach (array_keys($results) as $key) {
            $results[$key] = $this->items[$key];
        }

        return new static($results);
    }

    public function sortByDesc($callback, $options = SORT_REGULAR)
    {
        return $this->sortBy($callback, $options, true);
    }

    public function implode($value, $glue = null)
    {
        $first = $this->first();

        if (is_array($first) || is_object($first)) {
            return implode($glue, $this->pluck($value)->all());
        }

        return implode($value, $this->items);
    }

    public function pluck( $value, $key = null)
    {
        $results = [];


        $value = is_string($value) ? explode('.', $value) : $value;

        $key = is_null($key) || is_array($key) ? $key : explode('.', $key);

        foreach ($this->items as $item) {
            $itemValue =$this->get($item, $value);

            if (is_null($key)) {
                $results[] = $itemValue;
            } else {
                $itemKey = $this->get($item, $key);

                if (is_object($itemKey) && method_exists($itemKey, '__toString')) {
                    $itemKey = (string) $itemKey;
                }

                $results[$itemKey] = $itemValue;
            }
        }
        return new static($results);
    }

    public function toArray()
    {
        return array_map(function ($value) {
            return $value;
        }, $this->items);
    }

    public function take($limit)
    {
        if ($limit < 0) {
            return $this->slice($limit, abs($limit));
        }

        return $this->slice(0, $limit);
    }

    public function slice($offset, $length = null)
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    public function pad($size, $value)
    {
        return new static(array_pad($this->items, $size, $value));
    }

    public function only($keys)
    {
        if (is_null($keys)) {
            return $this->items;
        }

        if ($keys instanceof self) {
            $keys = $keys->all();
        }

        $keys = is_array($keys) ? $keys : func_get_args();
        return array_intersect_key($this->items, array_flip((array) $keys));
    }

    public function filter(callable $callback = null)
    {
        if ($callback) {
            return new static (array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
        }
        return new static (array_filter($this->items));
    }

    public function min($callback = null)
    {
        if(is_string($callback) && !is_callable($callback)){
            $callback=function ($item) use ($callback) {
                return $this->get($item, $callback);
            };
        }

        return $this->filter(function ($value) {
            return ! is_null($value);
        })->reduce(function ($result, $item) use ($callback) {
            $value = $callback($item);

            return is_null($result) || $value < $result ? $value : $result;
        });
    }

    public function max($callback = null)
    {

        if(is_string($callback) && !is_callable($callback)){
            $callback=function ($item) use ($callback) {
                return $this->get($item, $callback);
            };
        }

        return $this->filter(function ($value) {
            return ! is_null($value);
        })->reduce(function ($result, $item) use ($callback) {
            $value = $callback($item);

            return is_null($result) || $value > $result ? $value : $result;
        });
    }

    public function map(callable $callback)
    {
        $keys = array_keys($this->items);

        $items = array_map($callback, $this->items, $keys);

        return new static(array_combine($keys, $items));
    }

    public static function make($items = [])
    {
        return new static($items);
    }

    public function last( callable $callback = null, $default = null)
    {
        if (is_null($callback)) {
            return empty($this->items) ? value($default) : end($this->items);
        }
        $reverse=new static(array_reverse($this->items, true), $callback, $default);
        return $reverse->first();
    }

    public function keyBy($keyBy)
    {

        if(is_string($keyBy) && !is_callable($keyBy)){
            $keyBy=function ($item) use ($keyBy) {
                return $this->get($item, $keyBy);
            };
        }

        $results = [];

        foreach ($this->items as $key => $item) {
            $resolvedKey = $keyBy($item, $key);

            if (is_object($resolvedKey)) {
                $resolvedKey = (string) $resolvedKey;
            }

            $results[$resolvedKey] = $item;
        }

        return new static($results);
    }

    public function isNotEmpty()
    {
        return ! $this->isEmpty();
    }

    public function isEmpty()
    {
        return empty($this->items);
    }

    public function contains($key, $operator = null, $value = null)
    {
        if (func_num_args() == 1) {
            if (! is_string($key) && is_callable($key)) {
                $placeholder = new stdClass;

                return $this->first($key, $placeholder) !== $placeholder;
            }

            return in_array($key, $this->items);
        }

        return $this->contains($this->operatorForWhere(...func_get_args()));
    }

    public function prepend( $value, $key = null)
    {
        $array=$this->items;
        if (is_null($key)) {
            array_unshift($array, $value);
        } else {
            $array = [$key => $value] + $array;
        }

        return new static($array);
    }

    public function push($value,$key = null)
    {
        if (is_null($key)) {
            $this->items[] = $value;
        } else {
            $this->items[$key] = $value;
        }
         return new static($this->items);
    }

    public function reverse()
    {
        return new static(array_reverse($this->items, true));
    }

    public function skip($count)
    {
        return $this->slice($count);
    }

    public function sortKeys($options = SORT_REGULAR, $descending = false)
    {
        $items = $this->items;

        $descending ? krsort($items, $options) : ksort($items, $options);

        return new static($items);
    }

    public function sortKeysDesc($options = SORT_REGULAR)
    {
        return $this->sortKeys($options, true);
    }

    public function groupBy($groupBy, $preserveKeys = false)
    {
        if (is_array($groupBy)) {
            $nextGroups = $groupBy;
            $groupBy = array_shift($nextGroups);
        }


        if(is_string($groupBy) && !is_callable($groupBy)){
            $groupBy=function ($item) use ($groupBy) {
                return $this->get($item, $groupBy);
            };
        }

        $results = [];

        foreach ($this->items as $key => $value) {
            $groupKeys = $groupBy($value, $key);

            if (! is_array($groupKeys)) {
                $groupKeys = [$groupKeys];
            }

            foreach ($groupKeys as $groupKey) {
                $groupKey = is_bool($groupKey) ? (int) $groupKey : $groupKey;

                if (! array_key_exists($groupKey, $results)) {
                    $results[$groupKey] = new static;
                }

                $results[$groupKey]->offsetSet($preserveKeys ? $key : null, $value);
            }
        }

        $result = new static($results);

        if (! empty($nextGroups)) {
            return $result->map->groupBy($nextGroups, $preserveKeys);
        }

        return $result;
    }

    public function values()
    {
        return new static(array_values($this->items));
    }

    /**
     * Create a collection of all elements that do not pass a given truth test.
     *
     * @param  callable|mixed  $callback
     * @return static
     */
    public function reject($callback)
    {
        if ($this->useAsCallable($callback)) {
            return $this->filter(function ($value, $key) use ($callback) {
                return ! $callback($value, $key);
            });
        }

        return $this->filter(function ($item) use ($callback) {
            return $item != $callback;
        });
    }

    /**
     * Determine if the given value is callable, but not a string.
     *
     * @param  mixed  $value
     * @return bool
     */
    protected function useAsCallable($value)
    {
        return ! is_string($value) && is_callable($value);
    }


    /**
     * Execute a callback over each item.
     *
     * @param  callable  $callback
     * @return $this
     */
    public function each(callable $callback)
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

}//end class


