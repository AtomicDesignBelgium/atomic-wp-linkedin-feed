/* global IntersectionObserver */
(function () {
	'use strict';

	function initLayout(layout) {
		var nav = layout.querySelector('.atomic-linkedin-news-nav');
		if (!nav) {
			return;
		}

		var list = nav.querySelector('.atomic-linkedin-news-nav__list');
		if (!list) {
			return;
		}

		var items = Array.prototype.slice.call(list.querySelectorAll('.atomic-linkedin-news-nav__item'));
		if (!items.length) {
			return;
		}

		var posts = Array.prototype.slice.call(layout.querySelectorAll('.ermn-news-item[id], .atomic-linkedin-post[id]'));
		if (!posts.length) {
			return;
		}

		var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

		items.forEach(function (item) {
			var link = item.querySelector('.ermn-news-scroll-link, .atomic-linkedin-news-nav__link');
			if (!link) {
				return;
			}
			link.addEventListener('click', function (event) {
				var href = link.getAttribute('href') || '';
				if (href.charAt(0) !== '#') {
					return;
				}
				var id = href.slice(1);
				if (!id) {
					return;
				}
				var target = layout.querySelector('#' + CSS.escape ? CSS.escape(id) : ('[id="' + id + '"]'));
				if (!target) {
					return;
				}
				event.preventDefault();
				try {
					target.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' });
					if (history && history.replaceState) {
						history.replaceState(null, '', href);
					}
				} catch (e) {
					// Fallback to native anchor jump when smooth not supported.
					window.location.hash = href;
				}
			});
		});

		var map = {};
		items.forEach(function (item) {
			var link = item.querySelector('.atomic-linkedin-news-nav__link');
			if (!link) {
				return;
			}
			var href = link.getAttribute('href') || '';
			if (href.charAt(0) !== '#') {
				return;
			}
			map[href.slice(1)] = item;
		});

		function setActiveById(id) {
			items.forEach(function (it) {
				it.classList.remove('atomic-linkedin-news-nav__item--active');
				var a = it.querySelector('.atomic-linkedin-news-nav__link');
				if (a) {
					a.removeAttribute('aria-current');
				}
			});
			var activeItem = map[id];
			if (!activeItem) {
				return;
			}
			activeItem.classList.add('atomic-linkedin-news-nav__item--active');
			var link = activeItem.querySelector('.atomic-linkedin-news-nav__link');
			if (link) {
				link.setAttribute('aria-current', 'true');
			}
		}

		if (!('IntersectionObserver' in window)) {
			return;
		}

		var observer = new IntersectionObserver(
			function (entries) {
				var best = null;
				entries.forEach(function (entry) {
					if (!entry.isIntersecting) {
						return;
					}
					if (!best || entry.intersectionRatio > best.intersectionRatio) {
						best = entry;
					}
				});
				if (!best) {
					return;
				}
				var id = best.target && best.target.getAttribute('id');
				if (id) {
					setActiveById(id);
				}
			},
			{ root: null, threshold: [0.25, 0.5, 0.75] }
		);

		posts.forEach(function (post) {
			observer.observe(post);
		});
	}

	function init() {
		var layouts = document.querySelectorAll('.atomic-linkedin-news-layout[data-highlight-current="1"]');
		if (!layouts.length) {
			return;
		}
		Array.prototype.forEach.call(layouts, initLayout);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

