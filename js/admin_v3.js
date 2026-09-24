(function () {
    'use strict';

    const appId = 'davc_ldap_provisioning';

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.getElementById('davc-provisioning');
        const form = document.getElementById('davc-form');
        const profilesContainer = document.getElementById('davc-profiles');
        const template = document.getElementById('davc-profile-template');
        const addButton = document.getElementById('davc-add-profile');
        const status = document.getElementById('davc-save-status');
        if (!root || !form || !profilesContainer || !template || !addButton || !status) {
            return;
        }

        const maxProfiles = Number(root.dataset.maxProfiles || 20);

        function notify(message) {
            if (OC.Notification && OC.Notification.showTemporary) {
                OC.Notification.showTemporary(message);
            }
        }

        function field(card, name) {
            return card.querySelector('[data-field="' + name + '"]');
        }

        function setCollapsed(card, collapsed) {
            const body = card.querySelector('.davc-profile-body');
            const toggle = card.querySelector('.davc-profile-toggle');
            card.classList.toggle('is-collapsed', collapsed);
            body.hidden = collapsed;
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            card.querySelector('[data-role="toggle-label"]').textContent = collapsed
                ? t(appId, 'Expand')
                : t(appId, 'Collapse');
        }

        function updateProfileHeading(card) {
            const name = field(card, 'name').value.trim();
            card.querySelector('[data-role="profile-title"]').textContent = name || t(appId, 'New configuration');
            card.querySelector('[data-role="profile-id"]').textContent = card.dataset.profileId || t(appId, 'ID will be assigned when saved');
        }

        function updateCredentialSource(card) {
            const source = field(card, 'credential_source').value;
            const ldapBlock = card.querySelector('[data-role="ldap-credentials"]');
            const staticBlock = card.querySelector('[data-role="static-credentials"]');
            const loginAttribute = field(card, 'login_attribute');
            const secretAttribute = field(card, 'secret_attribute');
            const staticLogin = field(card, 'static_login');
            const staticSecret = field(card, 'static_secret');
            const isStatic = source === 'static';

            ldapBlock.hidden = isStatic;
            staticBlock.hidden = !isStatic;
            loginAttribute.required = !isStatic;
            secretAttribute.required = !isStatic;
            staticLogin.required = isStatic;
            staticSecret.required = isStatic && staticSecret.dataset.secretSet !== '1';
        }

        function readInitialValues(picker) {
            try {
                const values = JSON.parse(picker.dataset.values || '[]');
                return Array.isArray(values) ? values : [];
            } catch (error) {
                return [];
            }
        }

        function renderChips(picker) {
            const chips = picker.querySelector('[data-role="chips"]');
            chips.replaceChildren();
            picker._selected.forEach(function (label, id) {
                const chip = document.createElement('span');
                chip.className = 'davc-principal-chip';
                const text = document.createElement('span');
                text.textContent = label === id ? id : label + ' (' + id + ')';
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.setAttribute('aria-label', t(appId, 'Remove {name}', {name: id}));
                remove.textContent = '×';
                remove.addEventListener('click', function () {
                    picker._selected.delete(id);
                    renderChips(picker);
                });
                chip.append(text, remove);
                chips.appendChild(chip);
            });
        }

        function addPrincipal(picker, principal) {
            picker._selected.set(principal.id, principal.label || principal.id);
            renderChips(picker);
            const input = picker.querySelector('[data-role="principal-search"]');
            input.value = '';
            picker.querySelector('[data-role="results"]').hidden = true;
            input.focus();
        }

        function renderResults(picker, principals) {
            const results = picker.querySelector('[data-role="results"]');
            results.replaceChildren();
            principals.filter(function (principal) {
                return !picker._selected.has(principal.id);
            }).forEach(function (principal) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'davc-principal-result';
                button.textContent = principal.label === principal.id
                    ? principal.id
                    : principal.label + ' (' + principal.id + ')';
                button.addEventListener('click', function () {
                    addPrincipal(picker, principal);
                });
                results.appendChild(button);
            });
            results.hidden = results.childElementCount === 0;
        }

        function bindPicker(picker) {
            picker._selected = new Map();
            readInitialValues(picker).forEach(function (id) {
                picker._selected.set(String(id), String(id));
            });
            renderChips(picker);

            const input = picker.querySelector('[data-role="principal-search"]');
            const results = picker.querySelector('[data-role="results"]');
            let timer = null;
            let requestNumber = 0;
            input.addEventListener('input', function () {
                window.clearTimeout(timer);
                const query = input.value.trim();
                if (query === '') {
                    results.hidden = true;
                    return;
                }
                const currentRequest = ++requestNumber;
                timer = window.setTimeout(async function () {
                    try {
                        const url = OC.generateUrl('/apps/davc_ldap_provisioning/settings/principals')
                            + '?query=' + encodeURIComponent(query);
                        const response = await fetch(url, {
                            headers: {requesttoken: OC.requestToken},
                        });
                        const body = await response.json();
                        if (!response.ok || body.error) {
                            throw new Error(body.error || ('HTTP ' + response.status));
                        }
                        if (currentRequest === requestNumber) {
                            renderResults(picker, body[picker.dataset.kind] || []);
                        }
                    } catch (error) {
                        results.hidden = true;
                        notify(t(appId, 'Could not search users or groups'));
                    }
                }, 250);
            });
            input.addEventListener('blur', function () {
                window.setTimeout(function () {
                    results.hidden = true;
                }, 150);
            });
        }

        function updateTargetMode(card) {
            const allUsers = field(card, 'target_all').checked;
            const targetPickers = card.querySelector('[data-role="target-pickers"]');
            targetPickers.classList.toggle('is-disabled', allUsers);
            targetPickers.querySelectorAll('[data-role="principal-search"]').forEach(function (input) {
                input.disabled = allUsers;
            });
        }

        function updateCalendarColor(card) {
            field(card, 'calendar_color').disabled = !field(card, 'calendar_color_enabled').checked;
        }

        function principalValues(card, kind) {
            const picker = card.querySelector('.davc-principal-picker[data-kind="' + kind + '"]');
            return Array.from(picker._selected.keys());
        }

        function bindCard(card) {
            field(card, 'name').addEventListener('input', function () {
                updateProfileHeading(card);
            });
            field(card, 'credential_source').addEventListener('change', function () {
                updateCredentialSource(card);
            });
            field(card, 'target_all').addEventListener('change', function () {
                updateTargetMode(card);
            });
            field(card, 'calendar_color_enabled').addEventListener('change', function () {
                updateCalendarColor(card);
            });
            card.querySelector('.davc-profile-toggle').addEventListener('click', function () {
                setCollapsed(card, !card.classList.contains('is-collapsed'));
            });
            card.querySelector('.davc-remove-profile').addEventListener('click', function () {
                const name = field(card, 'name').value.trim() || t(appId, 'this configuration');
                if (window.confirm(t(appId, 'Remove {name}? Existing DAV services will not be deleted.', {name}))) {
                    card.remove();
                }
            });
            card.querySelector('.davc-sync-profile').addEventListener('click', async function () {
                try {
                    const saved = await saveSettings();
                    const profileIds = new Set(saved.state_changes || []);
                    profileIds.add(card.dataset.profileId);
                    await applyProfiles(profileIds);
                } catch (error) {
                    // saveSettings/applyProfile already rendered a useful error.
                }
            });
            card.querySelectorAll('.davc-principal-picker').forEach(bindPicker);
            updateProfileHeading(card);
            updateCredentialSource(card);
            updateTargetMode(card);
            updateCalendarColor(card);
            setCollapsed(card, card.classList.contains('is-collapsed'));
        }

        function createCard(profile) {
            const fragment = template.content.cloneNode(true);
            const card = fragment.querySelector('.davc-profile');
            card.dataset.profileId = profile.id || '';
            card.dataset.savedEnabled = profile.id ? (profile.enabled === false ? '0' : '1') : '';
            field(card, 'name').value = profile.name || '';
            field(card, 'enabled').checked = profile.enabled !== false;
            field(card, 'credential_source').value = profile.credential_source || 'static';
            field(card, 'login_attribute').value = profile.login_attribute || '';
            field(card, 'secret_attribute').value = profile.secret_attribute || '';
            field(card, 'static_login').value = profile.static_login || '';
            field(card, 'static_secret').dataset.secretSet = profile.static_secret_set === true ? '1' : '0';
            field(card, 'target_all').checked = profile.target_all === true;
            card.querySelector('.davc-principal-picker[data-kind="users"]').dataset.values = JSON.stringify(profile.target_users || []);
            card.querySelector('.davc-principal-picker[data-kind="groups"]').dataset.values = JSON.stringify(profile.target_groups || []);
            field(card, 'host').value = profile.host || '';
            field(card, 'port').value = profile.port || 443;
            field(card, 'path').value = profile.path || '/';
            field(card, 'secure_transport').checked = profile.secure_transport !== false;
            field(card, 'calendar_color_enabled').checked = profile.calendar_color_enabled === true;
            field(card, 'calendar_color').value = profile.calendar_color || '#0082c9';
            field(card, 'auto_enable_calendars').checked = profile.auto_enable_calendars === true;
            field(card, 'auto_enable_contacts').checked = profile.auto_enable_contacts === true;
            field(card, 'background_enabled').checked = profile.background_enabled === true;
            field(card, 'background_interval').value = profile.background_interval || 1800;
            bindCard(card);
            setCollapsed(card, false);
            return card;
        }

        function readCard(card) {
            return {
                id: card.dataset.profileId || '',
                name: field(card, 'name').value,
                enabled: field(card, 'enabled').checked,
                credential_source: field(card, 'credential_source').value,
                login_attribute: field(card, 'login_attribute').value,
                secret_attribute: field(card, 'secret_attribute').value,
                static_login: field(card, 'static_login').value,
                static_secret: field(card, 'static_secret').value,
                target_all: field(card, 'target_all').checked,
                target_users: principalValues(card, 'users'),
                target_groups: principalValues(card, 'groups'),
                host: field(card, 'host').value,
                port: Number(field(card, 'port').value),
                path: field(card, 'path').value,
                secure_transport: field(card, 'secure_transport').checked,
                calendar_color_enabled: field(card, 'calendar_color_enabled').checked,
                calendar_color: field(card, 'calendar_color').value,
                auto_enable_calendars: field(card, 'auto_enable_calendars').checked,
                auto_enable_contacts: field(card, 'auto_enable_contacts').checked,
                background_enabled: field(card, 'background_enabled').checked,
                background_interval: Number(field(card, 'background_interval').value),
            };
        }

        function setSyncStatus(card, message, isError) {
            const syncStatus = card.querySelector('[data-role="sync-status"]');
            syncStatus.textContent = message;
            syncStatus.classList.toggle('error', isError === true);
            syncStatus.title = message;
        }

        function ensureValidForm() {
            if (form.checkValidity()) {
                return true;
            }
            const invalid = form.querySelector(':invalid');
            if (invalid) {
                const card = invalid.closest('.davc-profile');
                if (card) {
                    setCollapsed(card, false);
                }
            }
            form.reportValidity();
            return false;
        }

        async function saveSettings() {
            if (!ensureValidForm()) {
                throw new Error(t(appId, 'Correct the highlighted fields before saving'));
            }

            status.textContent = t(appId, 'Saving…');
            const profileCards = Array.from(profilesContainer.querySelectorAll('.davc-profile'));
            const data = new URLSearchParams();
            data.set('profiles', JSON.stringify(profileCards.map(readCard)));

            try {
                const response = await fetch(OC.generateUrl('/apps/davc_ldap_provisioning/settings'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        'requesttoken': OC.requestToken,
                    },
                    body: data.toString(),
                });
                const body = await response.json();
                if (!response.ok || body.error) {
                    throw new Error(body.error || ('HTTP ' + response.status));
                }

                profileCards.forEach(function (card, index) {
                    const saved = body.config.profiles[index];
                    if (!saved) {
                        return;
                    }
                    card.dataset.profileId = saved.id;
                    card.dataset.savedEnabled = saved.enabled ? '1' : '0';
                    const secret = field(card, 'static_secret');
                    secret.value = '';
                    secret.dataset.secretSet = saved.static_secret_set ? '1' : '0';
                    secret.placeholder = saved.static_secret_set
                        ? t(appId, 'Password saved — leave blank to keep it')
                        : t(appId, 'Enter password');
                    updateCredentialSource(card);
                    updateProfileHeading(card);
                });
                status.textContent = t(appId, 'Saved');
                notify(t(appId, 'DAVC Provisioning settings saved'));
                return body;
            } catch (error) {
                status.textContent = t(appId, 'Error: {message}', {message: error.message});
                notify(t(appId, 'Could not save DAVC Provisioning settings'));
                throw error;
            }
        }

        async function applyProfile(card) {
            const button = card.querySelector('.davc-sync-profile');
            const profileId = card.dataset.profileId;
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = t(appId, 'Applying…');
            setSyncStatus(card, t(appId, 'Applying configuration…'), false);

            try {
                const response = await fetch(OC.generateUrl(
                    '/apps/davc_ldap_provisioning/settings/profiles/{profileId}/sync',
                    {profileId: profileId},
                ), {
                    method: 'POST',
                    headers: {requesttoken: OC.requestToken},
                });
                const body = await response.json();
                if (!response.ok || body.error) {
                    throw new Error(body.error || ('HTTP ' + response.status));
                }

                const summary = body.summary || {};
                const message = body.mode === 'deprovision'
                    ? t(appId, 'Disconnected: {ok}; errors: {failed}', {
                        ok: Number(summary.ok || 0),
                        failed: Number(summary.failed || 0),
                    })
                    : t(appId, 'Processed: {processed}; successful: {ok}; skipped: {skipped}; errors: {failed}', {
                        processed: Number(summary.processed || 0),
                        ok: Number(summary.ok || 0),
                        skipped: Number(summary.skipped || 0),
                        failed: Number(summary.failed || 0),
                    });
                const hasErrors = Number(summary.failed || 0) > 0;
                setSyncStatus(card, message, hasErrors);
                notify(message);
                return body;
            } catch (error) {
                const message = t(appId, 'Could not apply configuration: {message}', {message: error.message});
                setSyncStatus(card, message, true);
                notify(message);
                throw error;
            } finally {
                button.disabled = false;
                button.textContent = originalText;
            }
        }

        async function applyProfiles(profileIds) {
            for (const profileId of profileIds) {
                if (!profileId) {
                    continue;
                }
                const card = Array.from(profilesContainer.querySelectorAll('.davc-profile')).find(function (candidate) {
                    return candidate.dataset.profileId === profileId;
                });
                if (card) {
                    try {
                        await applyProfile(card);
                    } catch (error) {
                        // Continue applying other explicitly changed profiles.
                    }
                }
            }
        }

        profilesContainer.querySelectorAll('.davc-profile').forEach(bindCard);

        addButton.addEventListener('click', function () {
            if (profilesContainer.querySelectorAll('.davc-profile').length >= maxProfiles) {
                notify(t(appId, 'At most {count} profiles are allowed', {count: maxProfiles}));
                return;
            }
            const card = createCard({
                enabled: true,
                credential_source: 'static',
                target_all: false,
                target_users: [],
                target_groups: [],
                port: 443,
                path: '/',
                secure_transport: true,
                calendar_color_enabled: true,
                calendar_color: '#0082c9',
                auto_enable_calendars: false,
                auto_enable_contacts: false,
                background_enabled: false,
                background_interval: 1800,
            });
            profilesContainer.appendChild(card);
            field(card, 'name').focus();
        });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            try {
                const saved = await saveSettings();
                await applyProfiles(new Set(saved.state_changes || []));
            } catch (error) {
                // saveSettings already rendered the validation or request error.
            }
        });
    });
}());
