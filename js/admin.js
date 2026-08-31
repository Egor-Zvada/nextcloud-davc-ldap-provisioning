(function () {
    'use strict';

    const appId = 'davc_ldap_provisioning';

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.getElementById('davc-ldap-provisioning');
        const form = document.getElementById('davc-ldap-form');
        const profilesContainer = document.getElementById('davc-ldap-profiles');
        const template = document.getElementById('davc-ldap-profile-template');
        const addButton = document.getElementById('davc-ldap-add-profile');
        const status = document.getElementById('davc-ldap-save-status');
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

        function updateProfileHeading(card) {
            const name = field(card, 'name').value.trim();
            card.querySelector('[data-role="profile-title"]').textContent = name || t(appId, 'New configuration');
            card.querySelector('[data-role="profile-id"]').textContent = card.dataset.profileId || t(appId, 'ID will be assigned when saved');
        }

        function bindCard(card) {
            field(card, 'name').addEventListener('input', function () {
                updateProfileHeading(card);
            });
            card.querySelector('.davc-ldap-remove-profile').addEventListener('click', function () {
                const name = field(card, 'name').value.trim() || t(appId, 'this configuration');
                if (window.confirm(t(appId, 'Remove {name}? Existing DAV services will not be deleted.', {name}))) {
                    card.remove();
                }
            });
            updateProfileHeading(card);
        }

        function createCard(profile) {
            const fragment = template.content.cloneNode(true);
            const card = fragment.querySelector('.davc-ldap-profile');
            card.dataset.profileId = profile.id || '';
            field(card, 'name').value = profile.name || '';
            field(card, 'enabled').checked = profile.enabled !== false;
            field(card, 'login_attribute').value = profile.login_attribute || '';
            field(card, 'secret_attribute').value = profile.secret_attribute || '';
            field(card, 'host').value = profile.host || '';
            field(card, 'port').value = profile.port || 443;
            field(card, 'path').value = profile.path || '/';
            field(card, 'secure_transport').checked = profile.secure_transport !== false;
            field(card, 'auto_enable_calendars').checked = profile.auto_enable_calendars === true;
            field(card, 'auto_enable_contacts').checked = profile.auto_enable_contacts === true;
            bindCard(card);
            return card;
        }

        function readCard(card) {
            return {
                id: card.dataset.profileId || '',
                name: field(card, 'name').value,
                enabled: field(card, 'enabled').checked,
                login_attribute: field(card, 'login_attribute').value,
                secret_attribute: field(card, 'secret_attribute').value,
                host: field(card, 'host').value,
                port: Number(field(card, 'port').value),
                path: field(card, 'path').value,
                secure_transport: field(card, 'secure_transport').checked,
                auto_enable_calendars: field(card, 'auto_enable_calendars').checked,
                auto_enable_contacts: field(card, 'auto_enable_contacts').checked,
            };
        }

        profilesContainer.querySelectorAll('.davc-ldap-profile').forEach(bindCard);

        addButton.addEventListener('click', function () {
            if (profilesContainer.querySelectorAll('.davc-ldap-profile').length >= maxProfiles) {
                notify(t(appId, 'At most {count} profiles are allowed', {count: maxProfiles}));
                return;
            }
            const card = createCard({
                enabled: true,
                port: 443,
                path: '/',
                secure_transport: true,
                auto_enable_calendars: false,
                auto_enable_contacts: false,
            });
            profilesContainer.appendChild(card);
            field(card, 'name').focus();
        });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            status.textContent = t(appId, 'Saving…');

            const profileCards = Array.from(profilesContainer.querySelectorAll('.davc-ldap-profile'));
            const data = new URLSearchParams();
            data.set(
                'background_enabled',
                document.getElementById('davc-ldap-background-enabled').checked ? '1' : '0',
            );
            data.set('interval', document.getElementById('davc-ldap-interval').value);
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
                    if (body.config.profiles[index]) {
                        card.dataset.profileId = body.config.profiles[index].id;
                        updateProfileHeading(card);
                    }
                });
                status.textContent = t(appId, 'Saved');
                notify(t(appId, 'DAVC LDAP Provisioning settings saved'));
            } catch (error) {
                status.textContent = t(appId, 'Error: {message}', {message: error.message});
                notify(t(appId, 'Could not save DAVC LDAP Provisioning settings'));
            }
        });
    });
}());
