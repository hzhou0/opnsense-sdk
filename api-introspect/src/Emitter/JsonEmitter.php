<?php

namespace OpnsenseApiIntrospect\Emitter;

final class JsonEmitter
{
    /**
     * @param array<int,array<string,mixed>> $endpoints
     */
    public function emit(array $endpoints, array $meta): string
    {
        return json_encode(
            ['meta' => $meta, 'endpoints' => $endpoints],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n";
    }
}
