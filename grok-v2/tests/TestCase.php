<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Cada requisicao com Bearer resolve o usuario de novo (como em producao),
     * senao o guard reaproveitaria o usuario da requisicao anterior do mesmo teste.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $auth = $server['HTTP_AUTHORIZATION'] ?? ($this->defaultHeaders['Authorization'] ?? null);
        if ($auth !== null) {
            $this->app['auth']->forgetGuards();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
