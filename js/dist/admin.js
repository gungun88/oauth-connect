(function () {
  'use strict';

  if (typeof module !== 'undefined') {
    module.exports = module.exports || {};
  }

  function getDefault(module) {
    return module && module.default ? module.default : module;
  }

  var root = typeof flarum !== 'undefined' ? flarum : null;
  var compat = root && root.core && root.core.compat ? root.core.compat : {};
  var appModule = compat['admin/app'] || (typeof window !== 'undefined' ? window.app : null);
  var app = getDefault(appModule);
  var LoadingIndicator = getDefault(compat['common/components/LoadingIndicator'] || compat['components/LoadingIndicator']);

  if (!app || !app.initializers || !app.extensionData) {
    if (typeof console !== 'undefined' && console.error) {
      console.error('[iseekup/oauth-connect] Flarum admin app is not available.');
    }

    return;
  }

  var SCOPE_OPTIONS = [
    { key: 'user.read', label: 'Basic profile' },
    { key: 'user.email', label: 'Email address' },
    { key: 'user.stats', label: 'Activity counters' },
    { key: 'user.moderation', label: 'Moderation status' },
    { key: 'user.trust', label: 'Trust level' },
  ];

  function redraw() {
    if (typeof m !== 'undefined' && m.redraw) {
      m.redraw();
    }
  }

  function forumAttribute(key) {
    return app.forum && app.forum.attribute ? app.forum.attribute(key) || '' : '';
  }

  function api(path) {
    return forumAttribute('apiUrl').replace(/\/$/, '') + '/oauth-connect' + path;
  }

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

    try {
      return new Date(value).toLocaleString();
    } catch (error) {
      return value;
    }
  }

  function errorMessage(error) {
    if (error && error.response) {
      if (error.response.error) return error.response.error;
      if (error.response.errors && error.response.errors[0] && error.response.errors[0].detail) {
        return error.response.errors[0].detail;
      }
    }

    return error && error.message ? error.message : 'Request failed.';
  }

  function scopeBadges(scopes) {
    return (scopes || []).map(function (scope) {
      return m('code.OAuthConnectScope', scope);
    });
  }

  function OAuthConnectSettings() {}

  OAuthConnectSettings.prototype.oninit = function () {
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
  };

  OAuthConnectSettings.prototype.load = function () {
    var self = this;

    self.loading = true;
    self.error = null;

    return Promise.all([
      app.request({ method: 'GET', url: api('/clients') }),
      app.request({ method: 'GET', url: api('/authorizations') }),
    ]).then(function (responses) {
      self.clients = responses[0].data || [];
      self.authorizations = responses[1].data || [];
    }, function (error) {
      self.error = errorMessage(error);
    }).then(function () {
      self.loading = false;
      redraw();
    });
  };

  OAuthConnectSettings.prototype.view = function () {
    return m('.OAuthConnectPage', [
      this.error ? m('.Alert.Alert--error', this.error) : null,
      this.secretNotice ? this.secretPanel() : null,
      this.endpointPanel(),
      this.createClientPanel(),
      this.clientsPanel(),
      this.authorizationsPanel(),
    ]);
  };

  OAuthConnectSettings.prototype.endpointPanel = function () {
    var baseUrl = forumAttribute('baseUrl').replace(/\/$/, '');
    var apiUrl = forumAttribute('apiUrl').replace(/\/$/, '');

    return m('.OAuthConnectPanel', [
      m('h3', 'OAuth2 endpoints'),
      m('div.OAuthConnectEndpoints', [
        this.endpointRow('Authorization', baseUrl + '/oauth2/authorize'),
        this.endpointRow('Token', baseUrl + '/oauth2/token'),
        this.endpointRow('UserInfo', apiUrl + '/oauth/user'),
        this.endpointRow('UserInfo alias', apiUrl + '/user'),
      ]),
    ]);
  };

  OAuthConnectSettings.prototype.endpointRow = function (label, value) {
    return m('label', [
      m('span', label),
      m('input.FormControl', {
        value: value,
        readOnly: true,
        onclick: function (event) {
          event.currentTarget.select();
        },
      }),
    ]);
  };

  OAuthConnectSettings.prototype.secretPanel = function () {
    var self = this;

    return m('.OAuthConnectSecret', [
      m('div', [
        m('strong', 'Client secret generated'),
        m('p.helpText', 'This secret is stored hashed and is shown only once. Copy it now.'),
      ]),
      m('input.FormControl', {
        value: self.secretNotice.client_secret,
        readOnly: true,
        onclick: function (event) {
          event.currentTarget.select();
        },
      }),
      m('button.Button', {
        type: 'button',
        onclick: function () {
          self.copySecret();
        },
      }, 'Copy'),
      m('button.Button.Button--link', {
        type: 'button',
        onclick: function () {
          self.secretNotice = null;
          redraw();
        },
      }, 'Dismiss'),
    ]);
  };

  OAuthConnectSettings.prototype.copySecret = function () {
    if (!this.secretNotice) return;

    if (typeof navigator !== 'undefined' && navigator.clipboard) {
      navigator.clipboard.writeText(this.secretNotice.client_secret);
    }
  };

  OAuthConnectSettings.prototype.createClientPanel = function () {
    var self = this;

    return m('.OAuthConnectPanel', [
      m('h3', 'Create OAuth2 client'),
      self.clientForm(self.newClient, {
        submitLabel: 'Create client',
        loading: self.saving,
        onsubmit: function (event) {
          self.createClient(event);
        },
      }),
    ]);
  };

  OAuthConnectSettings.prototype.clientsPanel = function () {
    var self = this;

    if (self.loading) {
      return m('.OAuthConnectPanel', LoadingIndicator ? m(LoadingIndicator) : 'Loading...');
    }

    return m('.OAuthConnectPanel', [
      m('h3', 'Clients'),
      self.clients.length === 0
        ? m('p.helpText', 'No OAuth2 clients have been created yet.')
        : m('table.OAuthConnectTable', [
          m('thead', m('tr', [
            m('th', 'Client'),
            m('th', 'Redirect URIs'),
            m('th', 'Scopes'),
            m('th', 'Status'),
            m('th', 'Actions'),
          ])),
          m('tbody', self.clients.map(function (client) {
            return self.clientRow(client);
          })),
        ]),
    ]);
  };

  OAuthConnectSettings.prototype.clientRow = function (client) {
    var self = this;
    var isEditing = self.editingClientId === client.client_id;

    if (isEditing) {
      return m('tr.OAuthConnectEditRow', [
        m('td', { colSpan: 5 }, self.clientForm(self.editClient, {
          submitLabel: 'Save client',
          loading: self.saving,
          onsubmit: function (event) {
            self.saveClient(event, client);
          },
          oncancel: function () {
            self.editingClientId = null;
            self.editClient = null;
          },
        })),
      ]);
    }

    return m('tr', [
      m('td', [
        m('strong', client.name),
        m('code.OAuthConnectClientId', client.client_id),
        client.description ? m('div.helpText', client.description) : null,
      ]),
      m('td', (client.redirect_uris || []).map(function (uri) {
        return m('div.OAuthConnectUri', uri);
      })),
      m('td', scopeBadges(client.scopes)),
      m('td', client.is_enabled ? m('span.OAuthConnectStatus--enabled', 'Enabled') : m('span.OAuthConnectStatus--disabled', 'Disabled')),
      m('td.OAuthConnectActions', [
        m('button.Button', {
          type: 'button',
          onclick: function () {
            self.startEdit(client);
          },
        }, 'Edit'),
        m('button.Button', {
          type: 'button',
          onclick: function () {
            self.resetSecret(client);
          },
        }, 'Reset secret'),
        m('button.Button', {
          type: 'button',
          onclick: function () {
            self.toggleClient(client);
          },
        }, client.is_enabled ? 'Disable' : 'Enable'),
        m('button.Button.Button--danger', {
          type: 'button',
          onclick: function () {
            self.deleteClient(client);
          },
        }, 'Delete'),
      ]),
    ]);
  };

  OAuthConnectSettings.prototype.clientForm = function (form, options) {
    return m('form.OAuthConnectForm', { onsubmit: options.onsubmit }, [
      m('.OAuthConnectFormGrid', [
        this.textInput('Name', form, 'name', true),
        this.textInput('Homepage URL', form, 'homepage_url'),
        this.textInput('Icon URL', form, 'icon_url'),
        m('label', [
          m('span', 'Enabled'),
          m('select.FormControl', {
            value: form.is_enabled ? '1' : '0',
            onchange: function (event) {
              form.is_enabled = event.currentTarget.value === '1';
            },
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
          oninput: function (event) {
            form.description = event.currentTarget.value;
          },
        }),
      ]),
      m('label', [
        m('span', 'Redirect URIs'),
        m('textarea.FormControl', {
          rows: 3,
          required: true,
          value: form.redirect_uris,
          placeholder: 'https://app.example.com/oauth/callback',
          oninput: function (event) {
            form.redirect_uris = event.currentTarget.value;
          },
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
              onchange: function (event) {
                form.scopes[scope.key] = event.currentTarget.checked;
              },
            }),
            m('span', scope.key + ' - ' + scope.label),
          ]);
        }),
      ]),
      m('.OAuthConnectFormActions', [
        m('button.Button.Button--primary', {
          type: 'submit',
          disabled: options.loading,
        }, options.loading ? 'Saving...' : options.submitLabel),
        options.oncancel ? m('button.Button', {
          type: 'button',
          onclick: options.oncancel,
        }, 'Cancel') : null,
      ]),
    ]);
  };

  OAuthConnectSettings.prototype.textInput = function (label, form, key, required) {
    return m('label', [
      m('span', label),
      m('input.FormControl', {
        type: 'text',
        required: !!required,
        value: form[key],
        oninput: function (event) {
          form[key] = event.currentTarget.value;
        },
      }),
    ]);
  };

  OAuthConnectSettings.prototype.createClient = function (event) {
    var self = this;

    event.preventDefault();
    self.saving = true;
    self.error = null;

    app.request({
      method: 'POST',
      url: api('/clients'),
      body: payloadFromForm(self.newClient),
    }).then(function (response) {
      self.secretNotice = response.data;
      self.newClient = freshForm();
      return self.load();
    }, function (error) {
      self.error = errorMessage(error);
    }).then(function () {
      self.saving = false;
      redraw();
    });
  };

  OAuthConnectSettings.prototype.startEdit = function (client) {
    this.editingClientId = client.client_id;
    this.editClient = clientToForm(client);
  };

  OAuthConnectSettings.prototype.saveClient = function (event, client) {
    var self = this;

    event.preventDefault();
    self.saving = true;
    self.error = null;

    app.request({
      method: 'PATCH',
      url: api('/clients/' + encodeURIComponent(client.client_id)),
      body: payloadFromForm(self.editClient),
    }).then(function () {
      self.editingClientId = null;
      self.editClient = null;
      return self.load();
    }, function (error) {
      self.error = errorMessage(error);
    }).then(function () {
      self.saving = false;
      redraw();
    });
  };

  OAuthConnectSettings.prototype.toggleClient = function (client) {
    var self = this;
    var form = clientToForm(client);

    form.is_enabled = !client.is_enabled;

    app.request({
      method: 'PATCH',
      url: api('/clients/' + encodeURIComponent(client.client_id)),
      body: payloadFromForm(form),
    }).then(function () {
      return self.load();
    }, function (error) {
      self.error = errorMessage(error);
      redraw();
    });
  };

  OAuthConnectSettings.prototype.resetSecret = function (client) {
    var self = this;

    if (!confirm('Reset this client secret and revoke existing tokens?')) return;

    app.request({
      method: 'POST',
      url: api('/clients/' + encodeURIComponent(client.client_id) + '/reset-secret'),
    }).then(function (response) {
      self.secretNotice = response.data;
      return self.load();
    }, function (error) {
      self.error = errorMessage(error);
      redraw();
    });
  };

  OAuthConnectSettings.prototype.deleteClient = function (client) {
    var self = this;

    if (!confirm('Delete this client and revoke all of its tokens?')) return;

    app.request({
      method: 'DELETE',
      url: api('/clients/' + encodeURIComponent(client.client_id)),
    }).then(function () {
      return self.load();
    }, function (error) {
      self.error = errorMessage(error);
      redraw();
    });
  };

  OAuthConnectSettings.prototype.authorizationsPanel = function () {
    var self = this;

    if (self.loading) return null;

    return m('.OAuthConnectPanel', [
      m('h3', 'Recent authorizations'),
      self.authorizations.length === 0
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
          m('tbody', self.authorizations.map(function (authorization) {
            return m('tr', [
              m('td', authorization.client_name || authorization.client_id),
              m('td', [
                authorization.display_name || authorization.username || ('User #' + authorization.user_id),
                authorization.username ? m('code.OAuthConnectClientId', authorization.username) : null,
              ]),
              m('td', scopeBadges(authorization.scopes)),
              m('td', displayDate(authorization.authorized_at)),
              m('td', authorization.revoked_at ? 'Revoked ' + displayDate(authorization.revoked_at) : 'Active'),
              m('td', !authorization.revoked_at ? m('button.Button.Button--danger', {
                type: 'button',
                onclick: function () {
                  self.revokeAuthorization(authorization);
                },
              }, 'Revoke') : null),
            ]);
          })),
        ]),
    ]);
  };

  OAuthConnectSettings.prototype.revokeAuthorization = function (authorization) {
    var self = this;

    if (!confirm('Revoke this user authorization and its tokens?')) return;

    app.request({
      method: 'POST',
      url: api('/authorizations/revoke'),
      body: {
        client_id: authorization.client_id,
        user_id: authorization.user_id,
      },
    }).then(function () {
      return self.load();
    }, function (error) {
      self.error = errorMessage(error);
      redraw();
    });
  };

  app.initializers.add('iseekup/oauth-connect', function () {
    try {
      app.extensionData.for('iseekup-oauth-connect').registerSetting(function () {
        return m(OAuthConnectSettings);
      });
    } catch (error) {
      if (typeof console !== 'undefined' && console.error) {
        console.error('[iseekup/oauth-connect] Admin UI registration failed.', error);
      }
    }
  });
})();
