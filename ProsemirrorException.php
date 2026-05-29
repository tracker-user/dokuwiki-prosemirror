<?php

namespace dokuwiki\plugin\prosemirror;

/**
 * Class ProsemirrorException
 *
 * A translatable exception
 *
 * @package dokuwiki\plugin\prosemirror
 */
class ProsemirrorException extends \RuntimeException
{
    /** @var array<string, mixed> */
    protected array $data = [];

    /**
     * @param string $key
     * @param mixed  $data
     *
     * @return void
     */
    public function addExtraData($key, $data)
    {
        $this->data[$key] = $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraData(): array
    {
        return $this->data;
    }
}
