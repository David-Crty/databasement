<?php

use App\Services\Adminer\CacheSessionHandler;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

beforeEach(function () {
    $this->cache = new Repository(new ArrayStore);
    $this->handler = new CacheSessionHandler($this->cache, 3600);
});

test('a written session reads back unchanged', function () {
    $data = "auth|a:1:{s:3:\"pwd\";s:4:\"\0bin\";}";

    $this->handler->write('abc', $data);

    expect($this->handler->read('abc'))->toBe($data);
});

test('an unknown session reads as empty', function () {
    expect($this->handler->read('missing'))->toBe('');
});

test('a destroyed session reads as empty', function () {
    $this->handler->write('abc', 'data');

    $this->handler->destroy('abc');

    expect($this->handler->read('abc'))->toBe('');
});

test('sessions are stored under their own prefix', function () {
    $this->handler->write('abc', 'data');

    expect($this->cache->has('adminer_session:abc'))->toBeTrue()
        ->and($this->cache->has('abc'))->toBeFalse();
});
