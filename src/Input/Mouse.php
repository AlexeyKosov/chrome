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
use HeadlessChromium\Exception\ElementNotFoundException;
use HeadlessChromium\Exception\EvaluationFailed;
use HeadlessChromium\Exception\JavascriptException;
use HeadlessChromium\Exception\NoResponseAvailable;
use HeadlessChromium\Exception\OperationTimedOut;
use HeadlessChromium\Page;
use HeadlessChromium\Utils;

class Mouse
{
    public const BUTTON_LEFT = 'left';
    public const BUTTON_NONE = 'none';
    public const BUTTON_RIGHT = 'right';
    public const BUTTON_MIDDLE = 'middle';

    /**
     * @var Page
     */
    protected $page;

    protected $x = 0;
    protected $y = 0;

    protected $button = self::BUTTON_NONE;

    /**
     * @param Page $page
     */
    public function __construct(Page $page)
    {
        $this->page = $page;
    }

    /**
     * @param int        $x
     * @param int        $y
     * @param array|null $options
     *
     * @return $this
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    public function move(int $x, int $y, array $options = null): Mouse
    {
        $this->page->assertNotClosed();

        // get origin of the move
        $originX = $this->x;
        $originY = $this->y;

        // set new position after move
        $this->x = $x;
        $this->y = $y;

        // number of steps to achieve the move
        $steps = $options['steps'] ?? 1;
        if ($steps <= 0) {
            throw new \InvalidArgumentException('options "steps" for mouse move must be a positive integer');
        }

        // move
        for ($i = 1; $i <= $steps; ++$i) {
            $this->page->getSession()->sendMessageSync(new Message('Input.dispatchMouseEvent', [
                'x' => $originX + ($this->x - $originX) * ($i / $steps),
                'y' => $originY + ($this->y - $originY) * ($i / $steps),
                'type' => 'mouseMoved',
            ]));
        }

        return $this;
    }

    /**
     * @param array|null $options
     *
     * @return Mouse
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    public function press(array $options = null): Mouse
    {
        $this->page->assertNotClosed();
        $this->page->getSession()->sendMessageSync(new Message('Input.dispatchMouseEvent', [
            'x' => $this->x,
            'y' => $this->y,
            'type' => 'mousePressed',
            'button' => $options['button'] ?? self::BUTTON_LEFT,
            'clickCount' => 1,
        ]));

        return $this;
    }

    /**
     * @param array|null $options
     *
     * @return Mouse
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    public function release(array $options = null): Mouse
    {
        $this->page->assertNotClosed();
        $this->page->getSession()->sendMessageSync(new Message('Input.dispatchMouseEvent', [
            'x' => $this->x,
            'y' => $this->y,
            'type' => 'mouseReleased',
            'button' => $options['button'] ?? self::BUTTON_LEFT,
            'clickCount' => 1,
        ]));

        return $this;
    }

    /**
     * @param array|null $options
     *
     * @return Mouse
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    public function click(array $options = null): Mouse
    {
        $this->press($options);
        $this->release($options);

        return $this;
    }

    /**
     * Scroll up using the mouse wheel.
     *
     * @param int $distance Distance in pixels
     *
     * @return $this
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     *
     * @throws CommunicationException
     */
    public function scrollUp(int $distance): Mouse
    {
        return $this->scroll((-1 * \abs($distance)));
    }

    /**
     * Scroll down using the mouse wheel.
     *
     * @param int $distance Distance in pixels
     *
     * @return $this
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    public function scrollDown(int $distance): Mouse
    {
        return $this->scroll(\abs($distance));
    }

    /**
     * Scroll a positive or negative distance using the mouseWheel event type.
     *
     * @param int $distanceY Distance in pixels for the Y axis
     * @param int $distanceX (optional) Distance in pixels for the X axis
     *
     * @throws \HeadlessChromium\Exception\CommunicationException
     * @throws \HeadlessChromium\Exception\NoResponseAvailable
     * @throws \HeadlessChromium\Exception\OperationTimedOut
     *
     * @return $this
     * @throws CommunicationException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    private function scroll(int $distanceY, int $distanceX = 0): self
    {
        $this->page->assertNotClosed();

        $scollableArea = $this->page->getLayoutMetrics()->getContentSize();
        $visibleArea = $this->page->getLayoutMetrics()->getVisualViewport();

        $distanceX = $this->getMaximumDistance($distanceX, $visibleArea['pageX'], $scollableArea['width']);
        $distanceY = $this->getMaximumDistance($distanceY, $visibleArea['pageY'], $scollableArea['height']);

        $targetX = $visibleArea['pageX'] + $distanceX;
        $targetY = $visibleArea['pageY'] + $distanceY;

        // make sure the mouse is on the screen
        $this->move($this->x, $this->y);

        // scroll
        $this->page->getSession()->sendMessageSync(new Message('Input.dispatchMouseEvent', [
            'type' => 'mouseWheel',
            'x' => $this->x,
            'y' => $this->y,
            'deltaX' => $distanceX,
            'deltaY' => $distanceY,
        ]));

        // wait until the scroll is done
        Utils::tryWithTimeout(30000 * 1000, $this->waitForScroll($targetX, $targetY));

        // set new position after move
        $this->x += $distanceX;
        $this->y += $distanceY;

        return $this;
    }

    /**
     * Scroll in both X and Y axis until the given boundaries fit in the screen.
     *
     * This method currently scrolls only to right and bottom. If the desired element is outside the visible screen
     * to the left or top, thie method will not work. Its visibility will stay private until it works for both cases.
     *
     * @param int $right  The element right boundary
     * @param int $bottom The element bottom boundary
     *
     * @return self
     * @throws CommunicationException
     * @throws CommunicationException\ResponseHasError
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     */
    private function scrollToBoundary(int $right, int $bottom): self
    {
        $visibleArea = $this->page->getLayoutMetrics()->getLayoutViewport();

        $distanceX = $distanceY = 0;

        if ($right > $visibleArea['clientWidth']) {
            $distanceX = $right - $visibleArea['clientWidth'];
        }

        if ($bottom > $visibleArea['clientHeight']) {
            $distanceY = $bottom - $visibleArea['clientHeight'];
        }

        return $this->scroll($distanceY, $distanceX);
    }

    /**
     * Find an element and move the mouse to a random position over it.
     *
     * The search could result in several elements. The $position param can be used to select a specific element.
     * The given position can only be between 1 and the maximum number or elements. It will be adjusted to the
     * minimum and maximum values if needed.
     *
     * Example:
     * $page->mouse()->find('#a'):
     * $page->mouse()->find('.a', 2);
     *
     * @see https://developer.mozilla.org/docs/Web/API/Document/querySelector
     *
     * @param string $selectors selectors to use with document.querySelector
     * @param int    $position  (optional) which element of the result set should be used
     *
     * @return $this
     * @throws CommunicationException
     * @throws CommunicationException\ResponseHasError
     * @throws ElementNotFoundException
     * @throws NoResponseAvailable
     * @throws OperationTimedOut
     * @throws EvaluationFailed
     */
    public function find(string $selectors, int $position = 1): self
    {
        $this->page->assertNotClosed();

        --$position;

        try {
            $elementList = $this->page
                ->evaluate('JSON.parse(JSON.stringify(document.querySelectorAll("'.$selectors.'")));')
                ->getReturnValue();

            $position = \max(0, (\count($elementList) - 1));

            $element = $this->page
                ->evaluate('JSON.parse(JSON.stringify(document.querySelectorAll("'.$selectors.'")['.$position.'].getBoundingClientRect()));')
                ->getReturnValue();
        } catch (JavascriptException $exception) {
            throw new ElementNotFoundException('The search for "'.$selectors.'" returned no elements.');
        }

        if (false === \array_key_exists('x', $element)) {
            throw new ElementNotFoundException('The search for "'.$selectors.'" returned an element with no position.');
        }

        $rightBoundary = \floor($element['right']);
        $bottomBoundary = \floor($element['bottom']);

        $positionX = \mt_rand(\ceil($element['left']), $rightBoundary);
        $positionY = \mt_rand(\ceil($element['top']), $bottomBoundary);

        $this->scrollToBoundary($rightBoundary, $bottomBoundary)
            ->move(
                ($positionX - $this->x),
                ($positionY - $this->y)
            );

        return $this;
    }

    /**
     * Get the maximum distance to scroll a page.
     *
     * @param int $distance Distance to scroll, positive or negative
     * @param int $current  Current position
     * @param int $maximum  Maximum posible distance
     *
     * @return int allowed distance to scroll
     */
    private function getMaximumDistance(int $distance, int $current, int $maximum): int
    {
        $result = $current + $distance;

        if ($result < 0) {
            return $distance + \abs($result);
        }

        if ($result > $maximum) {
            return $maximum - $current;
        }

        return $distance;
    }

    /**
     * Wait for the browser to process the scroll command.
     *
     * Return the number of microseconds to wait before trying again or true in case of success.
     *
     * @see \HeadlessChromium\Utils::tryWithTimeout
     *
     * @param int $targetX
     * @param int $targetY
     *
     * @throws \HeadlessChromium\Exception\OperationTimedOut
     *
     * @return bool|\Generator
     */
    private function waitForScroll(int $targetX, int $targetY)
    {
        while (true) {
            $visibleArea = $this->page->getLayoutMetrics()->getVisualViewport();

            if ($visibleArea['pageX'] === $targetX && $visibleArea['pageY'] === $targetY) {
                return true;
            }

            yield 1000;
        }
    }

    /**
     * Get the current mouse position.
     *
     * @return array [x, y]
     */
    public function getPosition(): array
    {
        return [
            'x' => $this->x,
            'y' => $this->y,
        ];
    }
}
