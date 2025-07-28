<?php

interface ParamStoreInterface
{
    /**
     * @param string $name
     * @return mixed
     */
    public function get($name);
}