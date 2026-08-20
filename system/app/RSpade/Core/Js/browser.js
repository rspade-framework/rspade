/*
 * Browser and DOM utility functions for the RSpade framework.
 * These functions handle browser detection, viewport utilities, and DOM manipulation.
 */

// ============================================================================
// BROWSER DETECTION
// ============================================================================

/**
 * Detects if user is on a mobile device or using mobile viewport
 * @returns {boolean} True if mobile device or viewport < 992px
 * @todo Improve user agent detection for all mobile devices
 */
function is_mobile() {
    if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
        return true;
    } else if ($(window).width() < 992) {
        // 992px = bootstrap 4 col-md-
        return true;
    } else {
        return false;
    }
}

/**
 * Detects if user is on desktop (not mobile)
 * @returns {boolean} True if not mobile device/viewport
 */
function is_desktop() {
    return !is_mobile();
}

/**
 * Detects the user's operating system
 * @returns {string} OS name: 'Mac OS', 'iPhone', 'iPad', 'Windows', 'Android-Phone', 'Android-Tablet', 'Linux', or 'Unknown'
 */
function get_os() {
    let user_agent = window.navigator.userAgent,
        platform = window.navigator.platform,
        macos_platforms = ['Macintosh', 'MacIntel', 'MacPPC', 'Mac68K'],
        windows_platforms = ['Win32', 'Win64', 'Windows', 'WinCE'],
        ios_platforms = ['iPhone', 'iPad', 'iPod'],
        os = null;

    let is_mobile_device = is_mobile();

    if (macos_platforms.indexOf(platform) !== -1) {
        os = 'Mac OS';
    } else if (ios_platforms.indexOf(platform) !== -1 && is_mobile_device) {
        os = 'iPhone';
    } else if (ios_platforms.indexOf(platform) !== -1 && !is_mobile_device) {
        os = 'iPad';
    } else if (windows_platforms.indexOf(platform) !== -1) {
        os = 'Windows';
    } else if (/Android/.test(user_agent) && is_mobile_device) {
        os = 'Android-Phone';
    } else if (/Android/.test(user_agent) && !is_mobile_device) {
        os = 'Android-Tablet';
    } else if (!os && /Linux/.test(platform)) {
        os = 'Linux';
    } else {
        os = 'Unknown';
    }

    return os;
}

/**
 * Detects if the user agent is a web crawler/bot
 * @returns {boolean} True if user agent appears to be a bot/crawler
 */
function is_crawler() {
    let user_agent = navigator.userAgent;
    let bot_pattern = /bot|spider|crawl|slurp|archiver|ping|search|dig|tracker|monitor|snoopy|yahoo|baidu|msn|ask|teoma|axios/i;

    return bot_pattern.test(user_agent);
}

// ============================================================================
// DOM SCROLLING UTILITIES
// ============================================================================

/**
 * Scrolls the containing scrollable box to CENTER a target, and only when the
 * target is not already visible. Distinct from scroll_anchor_into_view() below,
 * which aligns to the top and always scrolls: this one is for keeping a moving
 * selection in view (keyboard navigation through a long list), where scrolling
 * an already-visible item would be a jarring, pointless jump.
 *
 * @param {string|HTMLElement|jQuery} target - Target element to scroll into view
 */
function scroll_into_view_if_needed(target) {
    const $target = $(target);

    // The box that actually scrolls, at any nesting depth. This used to be
    // $target.parent() - the IMMEDIATE parent - which measured a non-scrolling
    // box and moved nothing whenever the target was nested even one level inside
    // the container. The comment always claimed the ancestor walk; now the code
    // performs it.
    const $parent = nearest_scrollable_ancestor($target);

    const target_height = $target.outerHeight();
    const scroll_position = $parent.scrollTop();

    // clientHeight, not jQuery .height(): it is the VISIBLE height of the scrolling
    // box in both cases, while .height() on the document element reports the whole
    // content height and would make every target look "already in view".
    const parent_height = $parent[0].clientHeight;

    // Distance from the top of the scrollable CONTENT down to the target. The two
    // container kinds measure differently and must not share one formula: an
    // overflow box holds its own border box still while its content moves under it,
    // whereas the document element IS the thing moving, so its rect already carries
    // the scroll offset. Adding scrollTop in the document case would count it twice.
    const is_document = $parent[0] === document.scrollingElement
        || $parent[0] === document.documentElement;

    const target_top = is_document
        ? $target.offset().top
        : ($target[0].getBoundingClientRect().top - $parent[0].getBoundingClientRect().top) + scroll_position;

    // Check if the target is out of view
    if (target_top < scroll_position || target_top + target_height > scroll_position + parent_height) {
        Debugger.console_debug('UI', 'Scrolling!', target_top);

        // Calculate the new scroll position to center the target
        let new_scroll_position = target_top + target_height / 2 - parent_height / 2;

        // Limit the scroll position between 0 and the maximum scrollable height
        new_scroll_position = Math.max(0, Math.min(new_scroll_position, $parent[0].scrollHeight - parent_height));

        // Scroll the parent to the new scroll position
        $parent.scrollTop(new_scroll_position);
    }
}

/**
 * Nearest scrollable ancestor of an element, or the document scrolling element.
 *
 * The single answer to "which box actually scrolls" - used by both scrolling
 * helpers here. An element is the answer when it declares overflow-y auto or
 * scroll AND is genuinely taller than its client box; a container that merely
 * declares overflow but never overflows is not scrollable and is skipped.
 * Nothing scrollable on the way up means the document scrolls.
 *
 * @param {jQuery} $el - Element to start from
 * @returns {jQuery} The scrollable ancestor, or the document scrolling element
 */
function nearest_scrollable_ancestor($el) {
    let $node = $($el).parent();

    while ($node.length && $node[0] !== document.body && $node[0] !== document.documentElement) {
        const overflow_y = $node.css('overflow-y');

        if ((overflow_y === 'auto' || overflow_y === 'scroll') && $node[0].scrollHeight > $node[0].clientHeight) {
            return $node;
        }

        $node = $node.parent();
    }

    return $(document.scrollingElement || document.documentElement);
}

/**
 * Scroll an anchor target into view within whichever container actually scrolls.
 *
 * Vertical offset is deliberately NOT computed here - set `scroll-margin-top` on
 * the target in CSS and the browser handles sticky-header clearance natively.
 *
 * @param {string|HTMLElement|jQuery} target - Element to reveal
 * @param {Object} [options]
 * @param {string} [options.behavior='auto'] - 'smooth', 'instant', or 'auto' to
 *        defer to the CSS scroll-behavior of the scrolling box
 */
function scroll_anchor_into_view(target, options = {}) {
    const $target = $(target);

    if (!$target.length) {
        return;
    }

    const $container = nearest_scrollable_ancestor($target);
    const behavior = options.behavior || 'auto';

    // Document-level scrolling: scrollIntoView honors scroll-margin-* directly.
    if ($container[0] === document.scrollingElement || $container[0] === document.documentElement) {
        $target[0].scrollIntoView({ behavior: behavior, block: 'start' });
        return;
    }

    // Container-level scrolling: position() is relative to the offset parent, so
    // difference the bounding rects instead - correct at any nesting depth.
    const target_rect = $target[0].getBoundingClientRect();
    const container_rect = $container[0].getBoundingClientRect();
    const scroll_margin = parseFloat($target.css('scroll-margin-top')) || 0;

    const new_scroll_top = $container.scrollTop() + (target_rect.top - container_rect.top) - scroll_margin;

    $container[0].scrollTo({ top: Math.max(0, new_scroll_top), behavior: behavior });
}

/**
 * Scrolls page to make target element visible if needed (with animation)
 * @param {string|HTMLElement|jQuery} target - Target element to scroll into view
 */
function scroll_page_into_view_if_needed(target) {
    const $target = $(target);

    // Calculate the absolute top position of the target relative to the document
    const target_top = $target.offset().top;

    const target_height = $target.outerHeight();
    const window_height = $(window).height();
    const window_scroll_position = $(window).scrollTop();

    // Check if the target is out of view
    if (target_top < window_scroll_position || target_top + target_height > window_scroll_position + window_height) {
        Debugger.console_debug('UI', 'Scrolling!', target_top);

        // Calculate the new scroll position to center the target
        const new_scroll_position = target_top + target_height / 2 - window_height / 2;

        // Animate the scroll to the new position
        $('html, body').animate(
            {
                scrollTop: new_scroll_position,
            },
            1000
        ); // duration of the scroll animation in milliseconds
    }
}

// ============================================================================
// DOM UTILITIES
// ============================================================================

/**
 * Waits for all images on the page to load
 * @param {Function} callback - Function to call when all images are loaded
 */
function wait_for_images(callback) {
    const $images = $('img'); // Get all img tags
    const total_images = $images.length;
    let images_loaded = 0;

    if (total_images === 0) {
        callback(); // if there are no images, immediately call the callback
    }

    $images.each(function () {
        const img = new Image();
        img.onload = function () {
            images_loaded++;
            if (images_loaded === total_images) {
                callback(); // call the callback when all images are loaded
            }
        };
        img.onerror = function () {
            images_loaded++;
            if (images_loaded === total_images) {
                callback(); // also call the callback if an image fails to load
            }
        };
        img.src = this.src; // this triggers the loading
    });
}

/**
 * Creates a jQuery element containing a non-breaking space
 * @returns {jQuery} jQuery span element with &nbsp;
 */
function $nbsp() {
    return $('<span>&nbsp;</span>');
}

/**
 * Escapes special characters in a jQuery selector
 * @param {string} id - Element ID to escape
 * @returns {string} jQuery selector string with escaped special characters
 * @warning Not safe for security-critical operations
 */
function escape_jq_selector(id) {
    return '#' + id.replace(/(:|\.|\[|\]|,|=|@)/g, '\\$1');
}