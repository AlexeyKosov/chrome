<?php

/*
 * This file is part of Chrome PHP.
 *
 * (c) Soufiane Ghzal <sghzal@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace HeadlessChromium\Input;

use HeadlessChromium\Communication\Message;
use HeadlessChromium\Exception\CommunicationException;
use HeadlessChromium\Exception\NoResponseAvailable;
use HeadlessChromium\Exception\OperationTimedOut;
use HeadlessChromium\Page;

class Keyboard
{
    use KeyboardKeys;

    /**
     * @var Page
     */
    protected $page;

    /**
     * @var int
     */
    protected $sleep = 0;

    /**
     * @param Page $page
     */
    public function __construct(Page $page)
    {
        $this->page = $page;
    }

    /**
     * Type a text string, char by char, without applying modifiers.
     *
     * @param string $text text string to be typed
     *
     * @return $this
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    public function typeText(string $text): Keyboard
    {
        $this->page->assertNotClosed();

        $length = \strlen($text);

        for ($i = 0; $i < $length; ++$i) {
            $this->page->getSession()->sendMessageSync(new Message('Input.dispatchKeyEvent', [
                'type' => 'char',
                'modifiers' => $this->getModifiers(),
                'text' => $text[$i],
            ]));

            \usleep($this->sleep);
        }

        return $this;
    }

    /**
     * Type a raw key using the rawKeyDown event, without sending any codes or modifiers.
     *
     * Example:
     *
     * ```php
     * $page->keyboard()->typeRawKey('Tab');
     * ```
     *
     * @param string $key single raw key to be typed
     *
     * @return $this
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    public function typeRawKey(string $key): self
    {
        $this->page->assertNotClosed();

        $this->onKeyPress($key);

        $this->page->getSession()->sendMessageSync(new Message('Input.dispatchKeyEvent', [
            'type' => 'rawKeyDown',
            'key' => $key,
        ]));

        \usleep($this->sleep);

        $this->release($key);

        return $this;
    }

    /**
     * Press and release a single key.
     *
     * Example:
     *
     * ```php
     * $page->keyboard()->type('a');
     * ```
     *
     * @param string $key single key to be typed
     *
     * @return $this
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    public function type(string $key): self
    {
        return $this->press($key)->release($key);
    }

    /**
     * Press a single key with key codes and modifiers.
     *
     * A key can be pressed multiple times sequentially. This is what happens
     * in a real browser when the user presses and holds down hown the key.
     *
     * Example:
     *
     * ```php
     * $page->keyboard()->press('Control')->press('c'); // press ctrl + c
     * ```
     *
     * @param string $key single key to be pressed
     *
     * @return $this
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    public function press(string $key): self
    {
        $this->page->assertNotClosed();

        $this->onKeyPress($key);

        $this->page->getSession()->sendMessageSync(new Message('Input.dispatchKeyEvent', [
            'type' => 'keyDown',
            'modifiers' => $this->getModifiers(),
            'text' => $key,
            'key' => $this->getCurrentKey(),
            'windowsVirtualKeyCode' => $this->getKeyCode(),
        ]));

        \usleep($this->sleep);

        return $this;
    }

    /**
     * Release a single key.
     *
     * A key is released only once, even if it was pressed multiple times.
     * If no key is given, all pressed keys will be released.
     *
     * Example:
     *
     * ```php
     * $page->keyboard()->release('Control'); // release Control
     * $page->keyboard()->release(); // release all
     * ```
     *
     * @param string|null $key (optional) single key to be released
     *
     * @return $this
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    public function release(string $key = null): self
    {
        $this->page->assertNotClosed();

        if (null === $key) {
            $this->releaseAll();

            return $this;
        }

        $this->onKeyRelease($key);

        $this->page->getSession()->sendMessageSync(new Message('Input.dispatchKeyEvent', [
            'type' => 'keyUp',
            'key' => $this->getCurrentKey(),
        ]));

        \usleep($this->sleep);

        return $this;
    }

    /**
     * Release all pressed keys.
     *
     * @return self
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    private function releaseAll(): self
    {
        foreach ($this->pressedKeys as $key => $value) {
            if (true === $value) {
                $this->release($key);
            }
        }

        return $this;
    }

    /**
     * Set the time interval between keystrokes in milliseconds.
     *
     * @param int $milliseconds
     *
     * @return $this
     */
    public function setKeyInterval(int $milliseconds): Keyboard
    {
        if ($milliseconds < 0) {
            $milliseconds = 0;
        }

        $this->sleep = $milliseconds * 1000;

        return $this;
    }

    /**
     * @return $this
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    public function tab(): Keyboard
    {
        return $this->typeRawKey('Tab');
    }

    /**
     * @return $this
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    public function enter(): Keyboard
    {
        return $this->press("\r");
    }

    /**
     * @param int $keyCode
     *
     * @return $this
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    public function typeKeyCode(int $keyCode): self
    {
        $this->page->assertNotClosed();

        $this->page->getSession()->sendMessageSync(new Message('Input.dispatchKeyEvent', [
            'type' => 'rawKeyDown',
            'windowsVirtualKeyCode' => $keyCode,
        ]));

        \usleep($this->sleep);

        $this->page->getSession()->sendMessageSync(new Message('Input.dispatchKeyEvent', [
            'type' => 'keyUp',
            'windowsVirtualKeyCode' => $keyCode,
        ]));

        \usleep($this->sleep);

        return $this;
    }
}
