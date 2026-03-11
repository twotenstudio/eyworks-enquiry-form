/**
 * EYWorks Enquiry Form v2.1 — AJAX + GTM + UTM passthrough
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var btn       = document.getElementById('eyworks-submit-btn');
        var formWrap  = document.getElementById('eyworks-form-container');
        var successEl = document.getElementById('eyworks-success');
        var errorEl   = document.getElementById('eyworks-error');

        if (!btn || !formWrap) return;

        var utmParams = getUtmParams();

        btn.addEventListener('click', function () {
            errorEl.style.display = 'none';
            formWrap.querySelectorAll('.eyworks-invalid').forEach(function (el) {
                el.classList.remove('eyworks-invalid');
            });

            // Get source select text label
            var sourceSelect = document.getElementById('ew-source');
            var sourceText   = sourceSelect.selectedIndex > 0 ? sourceSelect.options[sourceSelect.selectedIndex].text : '';

            var fields = {
                nursery:              val('ew-nursery'),
                first_name:           val('ew-child-first-name'),
                last_name:            val('ew-child-last-name'),
                dob:                  val('ew-child-dob'),
                gender:               val('ew-child-gender'),
                parent_first_name:    val('ew-parent-first-name'),
                parent_last_name:     val('ew-parent-last-name'),
                email:                val('ew-parent-email'),
                phone:                val('ew-phone'),
                postcode:             val('ew-postcode'),
                preffered_start_date: val('ew-start-date'),
                source:               val('ew-source'),
                source_text:          sourceText,
                agree_terms:          document.getElementById('ew-agree-terms').checked ? '1' : ''
            };

            // Validate mandatory
            var requiredMap = {
                first_name:        'ew-child-first-name',
                last_name:         'ew-child-last-name',
                parent_first_name: 'ew-parent-first-name',
                email:             'ew-parent-email',
                phone:             'ew-phone',
                agree_terms:       'ew-agree-terms'
            };

            var valid = true;
            Object.keys(requiredMap).forEach(function (key) {
                if (!fields[key]) {
                    var el = document.getElementById(requiredMap[key]);
                    if (el) el.classList.add('eyworks-invalid');
                    valid = false;
                }
            });

            if (!valid) {
                var firstInvalid = formWrap.querySelector('.eyworks-invalid');
                if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            // GTM: attempt
            pushDataLayer('tour_booking_attempted', { source: sourceText });

            // Disable button
            btn.disabled = true;
            btn.querySelector('.btn-text').style.display = 'none';
            btn.querySelector('.btn-loading').style.display = 'inline';

            // Build POST data
            var formData = new FormData();
            formData.append('action', 'eyworks_submit_enquiry');
            formData.append('nonce', eyworksForm.nonce);

            Object.keys(fields).forEach(function (key) {
                if (key !== 'agree_terms') {
                    formData.append(key, fields[key]);
                }
            });

            // UTM params
            formData.append('utm_medium', utmParams.utm_medium || '');
            formData.append('utm_source', utmParams.utm_source || '');
            formData.append('utm_campaign', utmParams.utm_campaign || '');
            formData.append('utm_content', utmParams.utm_content || '');
            formData.append('utm_term', utmParams.utm_term || '');

            // Submit
            fetch(eyworksForm.ajaxUrl, { method: 'POST', body: formData })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json.success) {
                    formWrap.style.display = 'none';
                    successEl.style.display = 'block';

                    // GTM: conversion
                    pushDataLayer('tour_booking_submitted', {
                        source:       sourceText,
                        postcode:     fields.postcode,
                        utm_source:   utmParams.utm_source || '',
                        utm_medium:   utmParams.utm_medium || '',
                        utm_campaign: utmParams.utm_campaign || ''
                    });

                    successEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    var errMsg = (json.data && json.data.message) ? json.data.message : 'Something went wrong.';
                    var debugInfo = (json.data && json.data.debug) ? '<br><small>' + JSON.stringify(json.data.debug) + '</small>' : '';
                    errorEl.innerHTML = '<p>' + errMsg + debugInfo + '</p>'
                        + '<p>Please try again or contact us at <a href="mailto:admin@theworkingmumsclub.co.uk">admin@theworkingmumsclub.co.uk</a>.</p>';
                    errorEl.style.display = 'block';
                    errorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    pushDataLayer('tour_booking_error', { error_message: errMsg });
                }
            })
            .catch(function (err) {
                errorEl.innerHTML = '<p>Network error. Please check your connection and try again.</p>';
                errorEl.style.display = 'block';
                errorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                pushDataLayer('tour_booking_error', { error_message: 'Network: ' + err.message });
            })
            .finally(function () {
                btn.disabled = false;
                btn.querySelector('.btn-text').style.display = 'inline';
                btn.querySelector('.btn-loading').style.display = 'none';
            });
        });
    });

    function val(id) {
        var el = document.getElementById(id);
        return el ? el.value.trim() : '';
    }

    function getUtmParams() {
        var params = {};
        var search = window.location.search;
        ['utm_medium', 'utm_source', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function (key) {
            var match = search.match(new RegExp('[?&]' + key + '=([^&]*)'));
            if (match) params[key] = decodeURIComponent(match[1]);
        });
        return params;
    }

    function pushDataLayer(event, params) {
        window.dataLayer = window.dataLayer || [];
        var obj = { event: event };
        if (params) Object.keys(params).forEach(function (k) { obj[k] = params[k]; });
        window.dataLayer.push(obj);
    }
})();
