<?php
declare(strict_types=1);

/** OpenAPI 3 document for ChatGPT Actions / external agents (OAuth2). */
final class OpenApiBuilder
{
    /** @return array<string,mixed> */
    public static function document(): array
    {
        $base = OAuthServer::publicBaseUrl();
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'FreeSaaS Product Ops',
                'version' => (string)(ModuleManager::get('llm')['version'] ?? '0.1.0'),
                'description' => 'Auto-generated from module agent.ops. Authorize with OAuth2 (ops.read / ops.write).',
            ],
            'servers' => [
                ['url' => $base],
            ],
            'paths' => [
                '/llm/api/tools' => [
                    'get' => [
                        'operationId' => 'listTools',
                        'summary' => 'List available product tools for the authorized user',
                        'security' => [['oauth2' => [OAuthServer::SCOPE_READ]]],
                        'responses' => [
                            '200' => [
                                'description' => 'Tool list',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['type' => 'object'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '/llm/api/tools/call' => [
                    'post' => [
                        'operationId' => 'callTool',
                        'summary' => 'Invoke a product tool by name (function name or dotted op id)',
                        'security' => [['oauth2' => [OAuthServer::SCOPE_READ]]],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['name'],
                                        'properties' => [
                                            'name' => [
                                                'type' => 'string',
                                                'description' => 'Tool function name (module__area__action) or dotted op id',
                                            ],
                                            'arguments' => [
                                                'type' => 'object',
                                                'additionalProperties' => true,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Tool result',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['type' => 'object'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => [
                    'oauth2' => [
                        'type' => 'oauth2',
                        'flows' => [
                            'authorizationCode' => [
                                'authorizationUrl' => OAuthServer::authorizeUrl(),
                                'tokenUrl' => OAuthServer::tokenUrl(),
                                'scopes' => [
                                    OAuthServer::SCOPE_READ => 'List and call read-only product ops',
                                    OAuthServer::SCOPE_WRITE => 'Call write product ops (after OAuth consent)',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
