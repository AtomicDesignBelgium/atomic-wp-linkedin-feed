(function () {
	'use strict';

	function debounce(fn, wait) {
		var timer = null;
		return function () {
			var ctx = this;
			var args = arguments;
			if (timer) {
				clearTimeout(timer);
			}
			timer = setTimeout(function () {
				timer = null;
				fn.apply(ctx, args);
			}, wait || 150);
		};
	}

	function median(values) {
		if (!values.length) return 0;
		var sorted = values.slice().sort(function (a, b) { return a - b; });
		var mid = Math.floor(sorted.length / 2);
		if (sorted.length % 2 === 1) {
			return sorted[mid];
		}
		return Math.round((sorted[mid - 1] + sorted[mid]) / 2);
	}

	function clearTarget(root) {
		root.style.setProperty('--ermn-stack-target-height', '');
	}

	function measureItems(root) {
		var items = root.querySelectorAll('.ermn-news-item.atomic-social-card');
		var heights = [];
		var i, rect;
		for (i = 0; i < items.length; i++) {
			rect = items[i].getBoundingClientRect();
			if (rect && rect.height > 40) {
				heights.push(Math.round(rect.height));
			}
		}
		return heights;
	}

	function computeTarget(strategy, heights) {
		if (!heights.length) return 0;
		switch (strategy) {
			case 'minimum':
				return Math.min.apply(null, heights);
			case 'maximum':
				return Math.max.apply(null, heights);
			case 'estimated':
			default:
				return median(heights);
		}
	}

	function applyTarget(root, value) {
		if (value > 0) {
			root.style.setProperty('--ermn-stack-target-height', value + 'px');
		} else {
			root.style.setProperty('--ermn-stack-target-height', '');
		}
	}

	function diagnose(root, strategy, heights, applied) {
		if (!window.matchMedia || !window.matchMedia('(prefers-contrast: more)').media || !root.hasAttribute('data-stack-diagnostics')) {
			return;
		}
		var min = heights.length ? Math.min.apply(null, heights) : 0;
		var max = heights.length ? Math.max.apply(null, heights) : 0;
		var est = median(heights);
		// eslint-disable-next-line no-console
		console.debug('[ermn-stack]', {
			strategy: strategy,
			itemsMeasured: heights.length,
			minimum: min + 'px',
			estimated: est + 'px',
			maximum: max + 'px',
			applied: applied + 'px'
		});
	}

	function initStackHeight(root) {
		var strategy = (root.getAttribute('data-stack-height-strategy') || 'estimated').toLowerCase();
		if (['estimated', 'minimum', 'maximum'].indexOf(strategy) === -1) {
			strategy = 'estimated';
		}

		var grid = root.querySelector('.atomic-social-feed__grid, .ermn-news__grid');
		if (!grid) return;

		var items = root.querySelectorAll('.ermn-news-item.atomic-social-card');
		if (!items.length) return;

		var recalcScheduled = false;
		var pendingCount = 0;

		function recalc() {
			clearTarget(root);
			var heights = measureItems(root);
			var target = computeTarget(strategy, heights);
			applyTarget(root, target);
			diagnose(root, strategy, heights, target);
		}

		var schedule = debounce(function () {
			if (recalcScheduled) return;
			recalcScheduled = true;
			window.requestAnimationFrame(function () {
				recalcScheduled = false;
				recalc();
			});
		}, 140);

		function onIframeReady() {
			pendingCount -= 1;
			if (pendingCount <= 0) {
				schedule();
			}
		}

		var i, iframe;
		for (i = 0; i < items.length; i++) {
			iframe = items[i].querySelector('iframe');
			if (iframe) {
				pendingCount += 1;
				if (iframe.complete || iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
					onIframeReady();
				} else {
					iframe.addEventListener('load', onIframeReady, { once: true });
					iframe.addEventListener('error', onIframeReady, { once: true });
				}
			}
		}

		if (pendingCount === 0) {
			schedule();
		}

		if ('ResizeObserver' in window) {
			var observer = new ResizeObserver(schedule);
			observer.observe(grid);
			for (i = 0; i < items.length; i++) {
				observer.observe(items[i]);
			}
		}

		var resizeListener = schedule;
		window.addEventListener('resize', resizeListener, { passive: true });

		if ('matchMedia' in window) {
			var mql = window.matchMedia('(max-width: 960px)');
			var mqlListener = schedule;
			if (mql.addEventListener) {
				mql.addEventListener('change', mqlListener);
			} else if (mql.addListener) {
				mql.addListener(mqlListener);
			}
		}
	}

	function bootstrap() {
		var roots = document.querySelectorAll('.ermn-news-layout--stack, [data-stack-height-strategy="estimated"], [data-stack-height-strategy="minimum"], [data-stack-height-strategy="maximum"]');
		var seen = [];
		for (var i = 0; i < roots.length; i++) {
			if (seen.indexOf(roots[i]) === -1) {
				seen.push(roots[i]);
				initStackHeight(roots[i]);
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bootstrap);
	} else {
		bootstrap();
	}
})();
