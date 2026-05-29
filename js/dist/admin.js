(function () {
  'use strict';

  var compat = flarum.core.compat;
  var appModule = compat['admin/app'] || window.app;
  var app = appModule && appModule.default ? appModule.default : appModule;
  var ExtensionPage = compat['admin/components/ExtensionPage'] || compat['components/ExtensionPage'];
  var LoadingIndicator = compat['common/components/LoadingIndicator'] || compat['components/LoadingIndicator'];

  ExtensionPage = ExtensionPage && ExtensionPage.default ? ExtensionPage.default : ExtensionPage;
  LoadingIndicator = LoadingIndicator && LoadingIndicator.default ? LoadingIndicator.default : LoadingIndicator;

  var SCOPE_OPTIONS = [
    { key: 'user.read', label: 'Basic profile' },
    { key: 'user.email', label: 'Email address' },
    { key: 'user.stats', label: 'Activity counters' },
    { key: 'user.moderation', label: 'Moderation status' },
    { key: 'user.trust', label: 'Trust level' },
  ];

  function freshForm() {
    return {
      name: '',
      description: '',
      homepage_url: '',
      icon_url: '',
      redirect_uris: '',
      scopes: {
        'user.read': true,
        'user.email': false,
        'user.stats': false,
        'user.moderation': false,
        'user.trust': false,
      },
      is_enabled: true,
    };
  }

  function clientToForm(client) {
    var form = freshForm();
    form.name = client.name || '';
    form.description = client.description || '';
    form.homepage_url = client.homepage_url || '';
    form.icon_url = client.icon_url || '';
    form.redirect_uris = (client.redirect_uris || []).join('\n');
    form.is_enabled = !!client.is_enabled;

    SCOPE_OPTIONS.forEach(function (scope) {
      form.scopes[scope.key] = (client.scopes || []).indexOf(scope.key) !== -1;
    });

    form.scopes['user.read'] = true;

    return form;
  }

  function payloadFromForm(form) {
    var selectedScopes = SCOPE_OPTIONS.filter(function (scope) {
      return form.scopes[scope.key];
    }).map(function (scope) {
      return scope.key;
    });

    if (selectedScopes.indexOf('user.read') === -1) {
      selectedScopes.unshift('user.read');
    }

    return {
      name: form.name,
      description: form.description,
      homepage_url: form.homepage_url,
      icon_url: form.icon_url,
      redirect_uris: form.redirect_uris.split(/\r\n|\r|\n/).map(function (uri) {
        return uri.trim();
      }).filter(Boolean),
      scopes: selectedScopes,
      is_enabled: !!form.is_enabled,
    };
  }

  function displayDate(value) {
    if (!value) return '-';

    return new Date(value).toLocaleString();
  }

  function errorMessage(error) {
    if (error && error.response) {
      if (error.response.error) return error.response.error;
      if (error.response.errors && error.response.errors[0] && error.response.errors[0].detail) return error.response.errors[0].detail;
    }

    return error && error.message ? error.message : 'Request failed.';
  }

  function scopeBadges(scopes) {
    return (scopes || []).map(function (scope) {
      return m('code.OAuthConnectScope', scope);
    });
  }

  class OAuthConnectPage extends ExtensionPage {
    oninit(vnode) {
      super.oninit(vnode);

      this.loading = true;
      this.saving = false;
      this.clients = [];
      this.authorizations = [];
      this.error = null;
      this.newClient = freshForm();
      this.editingClientId = null;
      this.editClient = null;
      this.secretNotice = null;

      this.load();
    }

    api(path) {
      return app.forum.attribute('apiUrl') + '/oauth-connect' + path;
    }

    load() {
      this.loading = true;
      this.error = null;

      return Promise.all([
        app.request({ method: 'GET', url: this.api('/clients') }),
        app.request({ method: 'GET', url: this.api('/authorizations') }),
      ]).then((responses) => {
        this.clients = responses[0].data || [];
        this.authorizations = responses[1].data || [];
      }).catch((error) => {
        this.error = errorMessage(error);
      }).finally(() => {
        this.loading = false;
        m.redraw();
      });
    }

    content() {
      return m('.ExtensionPage-settings.OAuthConnectPage', m('.container', [
        this.error && m('.Alert.Alert--error', this.error),
        this.secretNotice && this.secretPanel(),
        this.endpointPanel(),
        this.createClientPanel(),
        this.clientsPanel(),
        this.authorizationsPanel(),
      ]));
    }

    endpointPanel() {
      var baseUrl = app.forum.attribute('baseUrl');
      var apiUrl = app.forum.attribute('apiUrl');

      return m('.OAuthConnectPanel', [
        m('h3', 'OAuth2 endpoints'),
        m('div.OAuthConnectEndpoints', [
          this.endpointRow('Authorization', baseUrl + '/oauth2/authorize'),
          this.endpointRow('Token', baseUrl + '/oauth2/token'),
          this.endpointRow('UserInfo', apiUrl + '/oauth/user'),
          this.endpointRow('UserInfo alias', apiUrl + '/user'),
        ]),
      ]);
    }

    endpointRow(label, value) {
      return m('label', [
        m('span', label),
        m('input.FormControl', { value: value, readonly: true, onclick: function (event) { event.currentTarget.select(); } }),
      ]);
    }

    secretPanel() {
      return m('.OAuthConnectSecret', [
        m('div', [
          m('strong', 'Client secret generated'),
          m('p.helpText', 'This secret is stored hashed and is shown only once. Copy it now.'),
        ]),
        m('input.FormControl', { value: this.secretNotice.client_secret, readonly: true, onclick: function (event) { event.currentTarget.select(); } }),
        m('button.Button', { type: 'button', onclick: () => this.copySecret() }, 'Copy'),
        m('button.Button.Button--link', { type: 'button', onclick: () => { this.secretNotice = null; } }, 'Dismiss'),
      ]);
    }

    copySecret() {
      if (!this.secretNotice) return;

      if (navigator.clipboard) {
        navigator.clipboard.writeText(this.secretNotice.client_secret);
      }
    }

    createClientPanel() {
      return m('.OAuthConnectPanel', [
        m('h3', 'Create OAuth2 client'),
        this.clientForm(this.newClient, {
          submitLabel: 'Create client',
          loading: this.saving,
          onsubmit: (event) => this.createClient(event),
        }),
      ]);
    }

    clientsPanel() {
      if (this.loading) {
        return m('.OAuthConnectPanel', LoadingIndicator ? m(LoadingIndicator) : 'Loading...');
      }

      return m('.OAuthConnectPanel', [
        m('h3', 'Clients'),
        this.clients.length === 0
          ? m('p.helpText', 'No OAuth2 clients have been created yet.')
          : m('table.OAuthConnectTable', [
            m('thead', m('tr', [
              m('th', 'Client'),
              m('th', 'Redirect URIs'),
              m('th', 'Scopes'),
              m('th', 'Status'),
              m('th', 'Actions'),
            ])),
            m('tbody', this.clients.map((client) => this.clientRow(client))),
          ]),
      ]);
    }

    clientRow(client) {
      var isEditing = this.editingClientId === client.client_id;

      if (isEditing) {
        return m('tr.OAuthConnectEditRow', [
          m('td', { colspan: 5 }, this.clientForm(this.editClient, {
            submitLabel: 'Save client',
            loading: this.saving,
            onsubmit: (event) => this.saveClient(event, client),
            oncancel: () => {
              this.editingClientId = null;
              this.editClient = null;
            },
          })),
        ]);
      }

      return m('tr', [
        m('td', [
          m('strong', client.name),
          m('code.OAuthConnectClientId', client.client_id),
          client.description && m('div.helpText', client.description),
        ]),
        m('td', (client.redirect_uris || []).map(function (uri) {
          return m('div.OAuthConnectUri', uri);
        })),
        m('td', scopeBadges(client.scopes)),
        m('td', client.is_enabled ? m('span.OAuthConnectStatus--enabled', 'Enabled') : m('span.OAuthConnectStatus--disabled', 'Disabled')),
        m('td.OAuthConnectActions', [
          m('button.Button', { type: 'button', onclick: () => this.startEdit(client) }, 'Edit'),
          m('button.Button', { type: 'button', onclick: () => this.resetSecret(client) }, 'Reset secret'),
          m('button.Button', { type: 'button', onclick: () => this.toggleClient(client) }, client.is_enabled ? 'Disable' : 'Enable'),
          m('button.Button.Button--danger', { type: 'button', onclick: () => this.deleteClient(client) }, 'Delete'),
        ]),
      ]);
    }

    clientForm(form, options) {
      return m('form.OAuthConnectForm', { onsubmit: options.onsubmit }, [
        m('.OAuthConnectFormGrid', [
          this.textInput('Name', form, 'name', true),
          this.textInput('Homepage URL', form, 'homepage_url'),
          this.textInput('Icon URL', form, 'icon_url'),
          m('label', [
            m('span', 'Enabled'),
            m('select.FormControl', {
              value: form.is_enabled ? '1' : '0',
              onchange: function (event) { form.is_enabled = event.currentTarget.value === '1'; },
            }, [
              m('option', { value: '1' }, 'Enabled'),
              m('option', { value: '0' }, 'Disabled'),
            ]),
          ]),
        ]),
        m('label', [
          m('span', 'Description'),
          m('textarea.FormControl', {
            rows: 2,
            value: form.description,
            oninput: function (event) { form.description = event.currentTarget.value; },
          }),
        ]),
        m('label', [
          m('span', 'Redirect URIs'),
          m('textarea.FormControl', {
            rows: 3,
            required: true,
            value: form.redirect_uris,
            placeholder: 'https://app.example.com/oauth/callback',
            oninput: function (event) { form.redirect_uris = event.currentTarget.value; },
          }),
          m('p.helpText', 'One exact redirect URI per line. Fragments are not allowed.'),
        ]),
        m('.OAuthConnectScopes', [
          m('span', 'Allowed scopes'),
          SCOPE_OPTIONS.map(function (scope) {
            return m('label.checkbox', [
              m('input', {
                type: 'checkbox',
                checked: !!form.scopes[scope.key],
                disabled: scope.key === 'user.read',
                onchange: function (event) { form.scopes[scope.key] = event.currentTarget.checked; },
              }),
              m('span', scope.key + ' - ' + scope.label),
            ]);
          }),
        ]),
        m('.OAuthConnectFormActions', [
          m('button.Button.Button--primary', { type: 'submit', disabled: options.loading }, options.loading ? 'Saving...' : options.submitLabel),
          options.oncancel && m('button.Button', { type: 'button', onclick: options.oncancel }, 'Cancel'),
        ]),
      ]);
    }

    textInput(label, form, key, required) {
      return m('label', [
        m('span', label),
        m('input.FormControl', {
          type: 'text',
          required: !!required,
          value: form[key],
          oninput: function (event) { form[key] = event.currentTarget.value; },
        }),
      ]);
    }

    createClient(event) {
      event.preventDefault();
      this.saving = true;
      this.error = null;

      app.request({
        method: 'POST',
        url: this.api('/clients'),
        body: payloadFromForm(this.newClient),
      }).then((response) => {
        this.secretNotice = response.data;
        this.newClient = freshForm();
        return this.load();
      }).catch((error) => {
        this.error = errorMessage(error);
      }).finally(() => {
        this.saving = false;
        m.redraw();
      });
    }

    startEdit(client) {
      this.editingClientId = client.client_id;
      this.editClient = clientToForm(client);
    }

    saveClient(event, client) {
      event.preventDefault();
      this.saving = true;
      this.error = null;

      app.request({
        method: 'PATCH',
        url: this.api('/clients/' + encodeURIComponent(client.client_id)),
        body: payloadFromForm(this.editClient),
      }).then(() => {
        this.editingClientId = null;
        this.editClient = null;
        return this.load();
      }).catch((error) => {
        this.error = errorMessage(error);
      }).finally(() => {
        this.saving = false;
        m.redraw();
      });
    }

    toggleClient(client) {
      var form = clientToForm(client);
      form.is_enabled = !client.is_enabled;

      app.request({
        method: 'PATCH',
        url: this.api('/clients/' + encodeURIComponent(client.client_id)),
        body: payloadFromForm(form),
      }).then(() => this.load()).catch((error) => {
        this.error = errorMessage(error);
        m.redraw();
      });
    }

    resetSecret(client) {
      if (!confirm('Reset this client secret and revoke existing tokens?')) return;

      app.request({
        method: 'POST',
        url: this.api('/clients/' + encodeURIComponent(client.client_id) + '/reset-secret'),
      }).then((response) => {
        this.secretNotice = response.data;
        return this.load();
      }).catch((error) => {
        this.error = errorMessage(error);
        m.redraw();
      });
    }

    deleteClient(client) {
      if (!confirm('Delete this client and revoke all of its tokens?')) return;

      app.request({
        method: 'DELETE',
        url: this.api('/clients/' + encodeURIComponent(client.client_id)),
      }).then(() => this.load()).catch((error) => {
        this.error = errorMessage(error);
        m.redraw();
      });
    }

    authorizationsPanel() {
      if (this.loading) return null;

      return m('.OAuthConnectPanel', [
        m('h3', 'Recent authorizations'),
        this.authorizations.length === 0
          ? m('p.helpText', 'No users have authorized clients yet.')
          : m('table.OAuthConnectTable', [
            m('thead', m('tr', [
              m('th', 'Client'),
              m('th', 'User'),
              m('th', 'Scopes'),
              m('th', 'Authorized'),
              m('th', 'Status'),
              m('th', 'Actions'),
            ])),
            m('tbody', this.authorizations.map((authorization) => m('tr', [
              m('td', authorization.client_name || authorization.client_id),
              m('td', [
                authorization.display_name || authorization.username || ('User #' + authorization.user_id),
                authorization.username && m('code.OAuthConnectClientId', authorization.username),
              ]),
              m('td', scopeBadges(authorization.scopes)),
              m('td', displayDate(authorization.authorized_at)),
              m('td', authorization.revoked_at ? 'Revoked ' + displayDate(authorization.revoked_at) : 'Active'),
              m('td', !authorization.revoked_at && m('button.Button.Button--danger', {
                type: 'button',
                onclick: () => this.revokeAuthorization(authorization),
              }, 'Revoke')),
            ]))),
          ]),
      ]);
    }

    revokeAuthorization(authorization) {
      if (!confirm('Revoke this user authorization and its tokens?')) return;

      app.request({
        method: 'POST',
        url: this.api('/authorizations/revoke'),
        body: {
          client_id: authorization.client_id,
          user_id: authorization.user_id,
        },
      }).then(() => this.load()).catch((error) => {
        this.error = errorMessage(error);
        m.redraw();
      });
    }
  }

  app.initializers.add('iseekup/oauth-connect', function () {
    app.extensionData.for('iseekup-oauth-connect').registerPage(OAuthConnectPage);
  });
})();
