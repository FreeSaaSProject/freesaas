<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

interface SemdBuilder
{
    public function kind(): string;

    /**
     * @param array<string,mixed> $instance docs instance row (mapped)
     * @param array<string,mixed> $ctx client, staff, org, identity, event…
     */
    public function build(array $instance, array $ctx): SemdDocument;
}
