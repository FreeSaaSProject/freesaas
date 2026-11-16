<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
interface AcquiringProvider
{
    public function id(): string;

    public function enabled(): bool;

    /**
     * @param array{order_id:int,client_id:int,amount:float,currency:string,description?:string,return_url?:string} $opts
     * @return array{ok:bool,provider_ref?:string,pay_url?:string,error?:string,meta?:array<string,mixed>}
     */
    public function createPayment(array $opts): array;

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,provider_ref?:string,status?:string,amount?:float,error?:string}
     */
    public function parseWebhook(array $payload): array;
}
