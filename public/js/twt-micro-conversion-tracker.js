(function () {
	if (typeof twtaeoMicroTracker === 'undefined') return;

	var device = /Mobi|Android/i.test(navigator.userAgent) ? 'mobile' : 'desktop';

	function send(eventType, pageUrl) {
		var body = new FormData();
		body.append('action', 'twtaeo_micro_conversion');
		body.append('nonce', twtaeoMicroTracker.nonce);
		body.append('event_type', eventType);
		body.append('page_url', pageUrl);
		body.append('device', device);
		fetch(twtaeoMicroTracker.ajaxUrl, { method: 'POST', body: body, keepalive: true });
	}

	document.addEventListener('click', function (e) {
		var link = e.target.closest('a');
		if (!link) return;
		var href = link.getAttribute('href') || '';
		if (href.indexOf('mailto:') === 0) {
			send('email_click', window.location.href);
		} else if (href.indexOf('tel:') === 0) {
			send('phone_tap', window.location.href);
		}
	});
})();
