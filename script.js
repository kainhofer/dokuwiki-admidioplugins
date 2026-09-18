/**
 * Release list of the admidioplugins plugin: "Add release" and edit/delete controls.
 *
 * The rendered page is cached for all users, so the controls are part of the HTML but hidden;
 * they are shown here when JSINFO says the user may edit the page the releases belong to. Every
 * request is checked again on the server.
 */
jQuery(function () {
    'use strict';

    const config = (JSINFO.plugins || {}).admidioplugins || {};
    const lang = (LANG.plugins || {}).admidioplugins || {};

    const text = function (key, value) {
        const string = lang[key] || key;
        return value === undefined ? string : string.replace('%s', value);
    };

    jQuery('.admidioplugins-releases').each(function () {
        const $box = jQuery(this);
        const pid = String($box.data('pid'));
        // The list may show the releases of another page (de:plugins:x shows en:plugins:x);
        // what counts is whether the user may edit the page the releases belong to.
        if ((config.editable || []).indexOf(pid) === -1) {
            return;
        }

        const $form = $box.find('.admidioplugins-form');
        const $derived = $form.find('.admidioplugins-derived');
        const $details = $form.find('.admidioplugins-details');
        const $message = $form.find('.admidioplugins-message');
        const $download = $form.find('[name=download]');
        let mode = null;   // 'add' or 'update'
        let rid = 0;
        let inspected = ''; // the download value the derived data belongs to

        $box.find('.admidioplugins-controls, .admidioplugins-release-actions').prop('hidden', false);

        const call = function (op, data) {
            return jQuery.ajax({
                url: DOKU_BASE + 'lib/exe/ajax.php',
                method: 'POST',
                dataType: 'json',
                data: jQuery.extend({
                    call: 'plugin_admidioplugins',
                    op: op,
                    pid: pid,
                    sectok: config.sectok
                }, data)
            });
        };

        const failed = function (xhr) {
            const response = xhr.responseJSON || {};
            showMessage(response.error || (text('error') + ' ' + xhr.status), true);
        };

        const showMessage = function (message, isError) {
            $message.text(message || '').toggleClass('error', !!isError);
        };

        const closeForm = function () {
            $form.prop('hidden', true).appendTo($box);
            mode = null;
        };

        const openForm = function (newMode, $anchor, legend) {
            mode = newMode;
            $form.get(0).reset();
            $form.find('legend').text(legend);
            $form.toggleClass('is-add', mode === 'add').toggleClass('is-update', mode === 'update');
            $derived.prop('hidden', true).empty();
            $details.prop('hidden', mode === 'add');
            showMessage('');
            inspected = '';
            $form.insertAfter($anchor).prop('hidden', false);
        };

        const showDerived = function (archive) {
            const rows = [
                ['derived_id', archive.id],
                ['derived_version', archive.version],
                ['derived_requires_admidio', archive.requires_admidio || text('any')],
                ['derived_requires_php', archive.requires_php || text('any')],
                ['derived_size', archive.size + ' B'],
                ['derived_sha256', archive.sha256]
            ];
            const $list = jQuery('<dl class="admidioplugins-facts"></dl>');
            rows.forEach(function (row) {
                $list.append(jQuery('<dt></dt>').text(text(row[0])), jQuery('<dd></dd>').text(row[1]));
            });
            $derived.empty().append($list).prop('hidden', false);
        };

        // Add a release
        $box.find('.admidioplugins-add').on('click', function () {
            if (mode === 'add') {
                closeForm();
                return;
            }
            openForm('add', jQuery(this).closest('.admidioplugins-controls'), text('add_title'));
            $form.find('[name=release_date]').val(new Date().toISOString().slice(0, 10));
            $download.trigger('focus');
        });

        $form.find('.admidioplugins-inspect').on('click', function () {
            const download = String($download.val()).trim();
            showMessage(text('checking'));
            $details.prop('hidden', true);
            $derived.prop('hidden', true);
            call('inspect', {download: download}).done(function (response) {
                inspected = download;
                showDerived(response.archive);
                $details.prop('hidden', false);
                showMessage('');
            }).fail(failed);
        });

        // Changing the address invalidates what was read from the previous one.
        $download.on('input', function () {
            if (mode === 'add' && String($download.val()).trim() !== inspected) {
                $details.prop('hidden', true);
                $derived.prop('hidden', true);
            }
        });

        // Edit a release
        $box.find('.admidioplugins-edit').on('click', function () {
            const $item = jQuery(this).closest('.admidioplugins-release');
            const release = $item.data('release');
            openForm('update', $item.children().last(), text('edit_title', release.version));
            rid = release.rid;
            ['release_status', 'requires_admidio', 'requires_php', 'notes'].forEach(function (name) {
                $form.find('[name=' + name + ']').val(release[name] || '');
            });
            $form.find('[name=release_date]').val(String(release.release_date || '').replace(/\//g, '-'));
        });

        // Delete a release
        $box.find('.admidioplugins-delete').on('click', function () {
            const release = jQuery(this).closest('.admidioplugins-release').data('release');
            if (!window.confirm(text('confirm_delete', release.version))) {
                return;
            }
            call('delete', {rid: release.rid}).done(function () {
                window.location.reload();
            }).fail(function (xhr) {
                window.alert((xhr.responseJSON || {}).error || text('error'));
            });
        });

        $form.find('.admidioplugins-cancel').on('click', closeForm);

        $form.on('submit', function (event) {
            event.preventDefault();
            const data = {};
            ['release_status', 'release_date', 'requires_admidio', 'requires_php', 'notes'].forEach(function (name) {
                data[name] = $form.find('[name=' + name + ']').val();
            });
            if (mode === 'add') {
                data.download = inspected;
            } else {
                data.rid = rid;
            }
            showMessage(text('saving'));
            call(mode, data).done(function () {
                window.location.reload();
            }).fail(failed);
        });
    });
});
