/**
 * Lead Attribution — first/landing touch in the visitor's own browser storage,
 * attached to form submissions and email/phone clicks. See
 * includes/class-lead-attribution.php for how the server reads it.
 */
(function () {
	if (typeof twtaeoLeadAttr === 'undefined') return;

	var cfg = twtaeoLeadAttr;
	var FIRST = 'twtaeo_attr_first';
	var LAND = 'twtaeo_attr_land';
	var FIRST_TTL = 90 * 864e5;   // first touch is forgotten after 90 days
	var SESSION_GAP = 30 * 6e4;   // 30 idle minutes start a new landing, like GA

	function load(key) {
		try { return JSON.parse(window.localStorage.getItem(key) || 'null'); } catch (e) { return null; }
	}
	function store(key, val) {
		try { window.localStorage.setItem(key, JSON.stringify(val)); } catch (e) {}
	}
	function host(url) {
		try { return new URL(url).hostname.replace(/^www\./i, '').toLowerCase(); } catch (e) { return ''; }
	}

	var now = Date.now();
	var own = (cfg.host || '').replace(/^www\./i, '').toLowerCase();
	var ref = document.referrer || '';
	var utm = '';
	try { utm = new URLSearchParams(window.location.search).get('utm_source') || ''; } catch (e) {}
	var external = !!utm || (ref !== '' && host(ref) !== own);
	var here = { ref: ref, utm: utm, url: window.location.href.split('#')[0], t: now };

	var first = load(FIRST);
	if (!first || !first.t || now - first.t > FIRST_TTL) {
		first = here;
		store(FIRST, first);
	}

	var land = load(LAND);
	if (!land || !land.t || external || now - (land.seen || land.t) > SESSION_GAP) {
		land = here;
	}
	land.seen = now;
	store(LAND, land);

	function touch(t) {
		if (!t) return null;
		return { ref: t.ref || '', utm: t.utm || '', url: t.url || '', age: Math.max(0, Math.round((Date.now() - t.t) / 1000)) };
	}
	function token() {
		var a = new Uint8Array(16);
		(window.crypto || window.msCrypto).getRandomValues(a);
		return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
	}
	function payload(tok) {
		return JSON.stringify({ token: tok, page: window.location.href.split('#')[0], first: touch(first), land: touch(land) });
	}
	function beacon(kind, tok, formRef, extra) {
		var body = new FormData();
		body.append('action', 'twtaeo_lead_beacon');
		body.append('kind', kind);
		body.append('attr', payload(tok));
		if (formRef) body.append('form_ref', formRef);
		if (extra) {
			Object.keys(extra).forEach(function (k) { body.append(k, extra[k]); });
		}
		if (navigator.sendBeacon && navigator.sendBeacon(cfg.ajaxUrl, body)) return;
		try { fetch(cfg.ajaxUrl, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' }); } catch (e) {}
	}

	// Forms whose plugin we read server-side once it accepts the entry. Every
	// other form is reported by beacon on submit (an attempt, not a confirmed entry).
	function adapted(form) {
		var cls = ' ' + (form.className || '') + ' ';
		return /^gform_\d+$/.test(form.id || '') || cls.indexOf(' wpcf7-form ') > -1 || cls.indexOf(' wpforms-form ') > -1 ||
			cls.indexOf(' forminator-custom-form ') > -1 ||
			cls.indexOf(' hs-form ') > -1; // legacy HubSpot embed: recorded from its own success callback below
	}
	function skip(form) {
		if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return true; // search and filter forms
		if (form.getAttribute('role') === 'search' || form.id === 'commentform') return true;
		// WooCommerce add-to-cart, cart, checkout and account forms are not leads.
		if (form.querySelector('[name="add-to-cart"]') || /(^|\s)(cart|checkout|woocommerce-[\w-]+)(\s|$)/.test(form.className || '')) return true;
		var action = form.getAttribute('action') || '';
		return /wp-login\.php|wp-admin|\/cart|\/checkout|\/my-account/i.test(action);
	}

	// Put the hidden field in the form and bring its value up to date. This
	// can't wait for the submit event: Gravity Forms 2.9 posts from code
	// without firing one, and other builders do the same. So the field goes in
	// as soon as the visitor focuses or clicks inside the form, and again on
	// the submit itself where one fires.
	//
	// fresh: adapted forms get a new token when the visitor presses submit —
	// their plugin records only accepted entries, and a second real entry from
	// the same page is a second lead. Beacon forms keep one token per form, so
	// a resubmit after a validation error updates the same lead.
	function prime(form, fresh) {
		if (!form || form.tagName !== 'FORM' || skip(form)) return null;
		var tok = (!fresh && form.getAttribute('data-twtaeo-token')) || token();
		form.setAttribute('data-twtaeo-token', tok);
		var input = form.querySelector('input[name="' + cfg.field + '"]');
		if (!input) {
			input = document.createElement('input');
			input.type = 'hidden';
			input.name = cfg.field;
			form.appendChild(input);
		}
		input.value = payload(tok);
		return tok;
	}
	function isSubmitControl(el) {
		var b = el && el.closest ? el.closest('button, input[type="submit"], input[type="image"]') : null;
		return !!b && (b.tagName !== 'BUTTON' || (b.getAttribute('type') || 'submit').toLowerCase() === 'submit');
	}
	function formOf(el) {
		return el && el.closest ? el.closest('form') : null;
	}

	Array.prototype.forEach.call(document.forms, function (f) { prime(f, false); });
	document.addEventListener('focusin', function (e) { prime(formOf(e.target), false); }, true);
	// Capture phase on the document runs before the button's own onclick
	// (Gravity Forms' gform.submission.handleButtonClick).
	document.addEventListener('click', function (e) {
		var form = formOf(e.target);
		if (!form) return;
		var fresh = isSubmitControl(e.target) && adapted(form);
		if (prime(form, fresh) && fresh) form.setAttribute('data-twtaeo-fresh', String(Date.now()));
	}, true);

	// Where a submit event does fire, refresh once more right before the form
	// plugin serializes the form, and report forms we have no adapter for.
	document.addEventListener('submit', function (e) {
		var form = e.target;
		var isAdapted = form && form.tagName === 'FORM' && adapted(form);
		// A submit-button click just issued this form a fresh token; an
		// Enter-key submit didn't, so it gets one here.
		var clicked = isAdapted && Date.now() - (+form.getAttribute('data-twtaeo-fresh') || 0) < 2000;
		var tok = prime(form, isAdapted && !clicked);
		if (!tok) return;

		if (!isAdapted) {
			beacon('form', tok, form.id || form.getAttribute('name') || (form.className || '').split(' ')[0] || 'form');
		}
	}, true);

	// HubSpot. Current embeds (the HubSpot WordPress plugin, "hs-form-frame")
	// render inside an iframe on HubSpot's domain, so the page never sees their
	// submit event; the embed announces a successful submission on the page
	// instead. Only answers to a "How did you hear" style question are sent
	// along: never names, emails or other fields.
	var SOURCE_Q = /hear|find_?us|found_?us|referr|how_?did_?you/i;
	function sourceAnswers(values) {
		var out = {};
		(Array.isArray(values) ? values : []).forEach(function (f) {
			if (f && f.name && SOURCE_Q.test(f.name)) {
				out[String(f.name).slice(0, 100)] = String(Array.isArray(f.value) ? f.value.join(' ') : f.value).slice(0, 200);
			}
		});
		return out;
	}
	window.addEventListener('hs-form-event:on-submission:success', function (e) {
		var d = e.detail || {};
		var tok = token();
		var form = null;
		try { form = window.HubSpotFormsV4 && window.HubSpotFormsV4.getFormFromEvent(e); } catch (err) {}
		var sent = false;
		function send(answers) {
			if (sent) return;
			sent = true;
			var conv = '';
			try { conv = (form && form.getConversionId && form.getConversionId()) || ''; } catch (err) {}
			beacon('form', tok, 'HubSpot ' + (d.formId || 'form'), {
				via: 'hubspot',
				entry: String(conv).slice(0, 40),
				answers: JSON.stringify(answers || {})
			});
		}
		if (form && form.getFormFieldValues) {
			// Field values come back over postMessage; don't let a slow reply lose the lead.
			setTimeout(function () { send({}); }, 1500);
			try {
				Promise.resolve(form.getFormFieldValues()).then(function (v) { send(sourceAnswers(v)); }, function () { send({}); });
			} catch (err) { send({}); }
		} else {
			send({});
		}
	});
	// Legacy HubSpot embeds (hbspt.forms.create) report through postMessage.
	window.addEventListener('message', function (e) {
		var m = e.data;
		if (m && m.type === 'hsFormCallback' && m.eventName === 'onFormSubmitted') {
			var vals = m.data && m.data.submissionValues ? Object.keys(m.data.submissionValues).map(function (k) { return { name: k, value: m.data.submissionValues[k] }; }) : [];
			beacon('form', token(), 'HubSpot ' + (m.id || 'form'), { via: 'hubspot', answers: JSON.stringify(sourceAnswers(vals)) });
		}
	});

	document.addEventListener('click', function (e) {
		var link = e.target && e.target.closest ? e.target.closest('a[href]') : null;
		if (!link) return;
		var href = (link.getAttribute('href') || '').toLowerCase();
		if (href.indexOf('mailto:') === 0) beacon('email_click', token());
		else if (href.indexOf('tel:') === 0) beacon('phone_tap', token());
	}, true);
})();
