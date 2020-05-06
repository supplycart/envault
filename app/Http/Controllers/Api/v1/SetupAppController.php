<?php

namespace App\Http\Controllers\Api\v1;

use App\App;
use App\Http\Controllers\Controller;

class SetupAppController extends Controller
{
    /**
     * @param \App\App $app
     * @param string $token
     * @return array
     */
    public function __invoke(App $app, $token)
    {
        $setupToken = $app->setup_tokens()->where([
            ['created_at', '>=', carbon()->subMinutes(10)],
            ['token', $token],
        ])->firstOrFail();

        return [
            'authToken' => $setupToken->user->createToken(uniqid())->plainTextToken,
            'app' => $setupToken->app->load('variables'),
        ];
    }
}