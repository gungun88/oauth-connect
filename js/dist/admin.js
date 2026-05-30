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
  var setRouteWithForcedRefresh = getDefault(compat['common/utils/setRouteWithForcedRefresh'] || compat['utils/setRouteWithForcedRefresh']);
  var EXTENSION_ID = 'iseekup-oauth-connect';

  if (!app || !app.initializers || !app.extensionData) {
    if (typeof console !== 'undefined' && console.error) {
      console.error('[iseekup/oauth-connect] Flarum admin app is not available.');
    }

    return;
  }

  var SCOPE_OPTIONS = [
    { key: 'user.read', labelKey: 'user_read', label: 'Basic profile' },
    { key: 'user.email', labelKey: 'user_email', label: 'Email address' },
    { key: 'user.stats', labelKey: 'user_stats', label: 'Activity counters' },
    { key: 'user.moderation', labelKey: 'user_moderation', label: 'Moderation status' },
    { key: 'user.trust', labelKey: 'user_trust', label: 'Trust level' },
  ];

  function t(key, params, fallback) {
    var id = 'iseekup-oauth-connect.admin.' + key;

    if (app.translator && app.translator.trans) {
      var translated = app.translator.trans(id, params || {});

      if (translated !== id) return translated;
    }

    return fallback ? interpolate(fallback, params || {}) : id;
  }

  function interpolate(text, params) {
    return String(text).replace(/\{([^}]+)\}/g, function (match, key) {
      return Object.prototype.hasOwnProperty.call(params, key) ? params[key] : match;
    });
  }

  function scopeLabel(scope) {
    return t('scopes.' + scope.labelKey, {}, scope.label);
  }

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

  function queryString(params) {
    var parts = [];

    Object.keys(params || {}).forEach(function (key) {
      var value = params[key];

      if (value === undefined || value === null || value === '') return;

      parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
    });

    return parts.length ? '?' + parts.join('&') : '';
  }

  function authorizationsApi(params) {
    var apiParams = Object.assign({}, params || {});

    if (apiParams.page !== undefined && apiParams.page !== null && apiParams.page !== '') {
      apiParams.page_number = apiParams.page;
      delete apiParams.page;
    }

    return api('/authorizations') + queryString(apiParams);
  }

  function authorizationsRoute(params) {
    var routeParams = {};

    Object.keys(params || {}).forEach(function (key) {
      var value = params[key];

      if (value !== undefined && value !== null && value !== '') {
        routeParams[key] = value;
      }
    });

    return app.route('oauthConnectAuthorizations', routeParams);
  }

  function setRoute(route) {
    if (typeof setRouteWithForcedRefresh === 'function') {
      setRouteWithForcedRefresh(route);
    } else {
      m.route.set(route);
    }
  }

  function extensionRoute(params) {
    var routeParams = { id: EXTENSION_ID };

    Object.keys(params || {}).forEach(function (key) {
      var value = params[key];

      if (value !== undefined && value !== null && value !== '') {
        routeParams[key] = value;
      }
    });

    return app.route('extension', routeParams);
  }

  function intParam(name, fallback) {
    var value = parseInt(m.route.param(name), 10);

    return isNaN(value) || value < 1 ? fallback : value;
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

    return error && error.message ? error.message : t('errors.request_failed', {}, 'Request failed.');
  }

  function scopeBadges(scopes) {
    return (scopes || []).map(function (scope) {
      return m('code.OAuthConnectScope', scope);
    });
  }

  function authorizationUser(authorization) {
    return [
      authorization.display_name || authorization.username || t('user_fallback', { id: authorization.user_id }, 'User #' + authorization.user_id),
      authorization.username ? m('code.OAuthConnectClientId', authorization.username) : null,
    ];
  }

  function authorizationStatus(authorization) {
    return authorization.revoked_at
      ? t('status.revoked', { date: displayDate(authorization.revoked_at) }, 'Revoked ' + displayDate(authorization.revoked_at))
      : t('status.active', {}, 'Active');
  }

  function authorizationTable(authorizations, onRevoke) {
    return m('table.OAuthConnectTable', [
      m('thead', m('tr', [
        m('th', t('table.client', {}, 'Client')),
        m('th', t('table.user', {}, 'User')),
        m('th', t('table.scopes', {}, 'Scopes')),
        m('th', t('table.authorized', {}, 'Authorized')),
        m('th', t('table.status', {}, 'Status')),
        m('th', t('table.actions', {}, 'Actions')),
      ])),
      m('tbody', authorizations.map(function (authorization) {
        return m('tr', [
          m('td', [
            authorization.client_name || authorization.client_id,
            m('code.OAuthConnectClientId', authorization.client_id),
          ]),
          m('td', authorizationUser(authorization)),
          m('td', scopeBadges(authorization.scopes)),
          m('td', displayDate(authorization.authorized_at)),
          m('td', authorizationStatus(authorization)),
          m('td', !authorization.revoked_at ? m('button.Button.Button--danger', {
            type: 'button',
            onclick: function () {
              onRevoke(authorization);
            },
          }, t('actions.revoke', {}, 'Revoke')) : null),
        ]);
      })),
    ]);
  }

  function localizeExtensionMetadata() {
    var extension = app.data && app.data.extensions ? app.data.extensions['iseekup-oauth-connect'] : null;

    if (extension) {
      if (extension.extra && extension.extra['flarum-extension']) {
        extension.extra['flarum-extension'].title = t('metadata_title', {}, extension.extra['flarum-extension'].title || 'OAuth Connect');
      }

      extension.description = t('description', {}, extension.description || 'OAuth2 provider for Flarum forums.');
      redraw();
    }
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

    var clientsRequest = app.request({
      method: 'GET',
      url: api('/clients'),
    }).then(function (response) {
      self.clients = response.data || [];
    }, function (error) {
      self.error = errorMessage(error);
    }).then(function () {
      self.loading = false;
      redraw();
    });

    app.request({
      method: 'GET',
      url: authorizationsApi({ limit: 20 }),
    }).then(function (response) {
      self.authorizations = response.data || [];
    }, function (error) {
      self.error = errorMessage(error);
    }).then(function () {
      redraw();
    });

    return clientsRequest;
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
      m('h3', t('endpoint_panel_title', {}, 'OAuth2 endpoints')),
      m('div.OAuthConnectEndpoints', [
        this.endpointRow(t('endpoints.authorization', {}, 'Authorization'), baseUrl + '/oauth2/authorize'),
        this.endpointRow(t('endpoints.token', {}, 'Token'), baseUrl + '/oauth2/token'),
        this.endpointRow(t('endpoints.user_info', {}, 'UserInfo'), apiUrl + '/oauth/user'),
        this.endpointRow(t('endpoints.user_info_alias', {}, 'UserInfo alias'), apiUrl + '/user'),
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
        m('strong', t('secret.title', {}, 'Client secret generated')),
        m('p.helpText', t('secret.help', {}, 'This secret is stored hashed and is shown only once. Copy it now.')),
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
      }, t('secret.copy', {}, 'Copy')),
      m('button.Button.Button--link', {
        type: 'button',
        onclick: function () {
          self.secretNotice = null;
          redraw();
        },
      }, t('secret.dismiss', {}, 'Dismiss')),
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
      m('h3', t('create_client_title', {}, 'Create OAuth2 client')),
      self.clientForm(self.newClient, {
        submitLabel: t('form.create_client', {}, 'Create client'),
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
      return m('.OAuthConnectPanel', LoadingIndicator ? m(LoadingIndicator) : t('loading', {}, 'Loading...'));
    }

    return m('.OAuthConnectPanel', [
      m('h3', t('clients_title', {}, 'Clients')),
      self.clients.length === 0
        ? m('p.helpText', t('no_clients', {}, 'No OAuth2 clients have been created yet.'))
        : m('table.OAuthConnectTable', [
          m('thead', m('tr', [
            m('th', t('table.client', {}, 'Client')),
            m('th', t('table.redirect_uris', {}, 'Redirect URIs')),
            m('th', t('table.scopes', {}, 'Scopes')),
            m('th', t('table.status', {}, 'Status')),
            m('th', t('table.actions', {}, 'Actions')),
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
          submitLabel: t('form.save_client', {}, 'Save client'),
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
      m('td', client.is_enabled ? m('span.OAuthConnectStatus--enabled', t('status.enabled', {}, 'Enabled')) : m('span.OAuthConnectStatus--disabled', t('status.disabled', {}, 'Disabled'))),
      m('td.OAuthConnectActions', [
        m('button.Button', {
          type: 'button',
          onclick: function () {
            self.startEdit(client);
          },
        }, t('actions.edit', {}, 'Edit')),
        m('button.Button', {
          type: 'button',
          onclick: function () {
            self.resetSecret(client);
          },
        }, t('actions.reset_secret', {}, 'Reset secret')),
        m('button.Button', {
          type: 'button',
          onclick: function () {
            self.toggleClient(client);
          },
        }, client.is_enabled ? t('actions.disable', {}, 'Disable') : t('actions.enable', {}, 'Enable')),
        m('button.Button.Button--danger', {
          type: 'button',
          onclick: function () {
            self.deleteClient(client);
          },
        }, t('actions.delete', {}, 'Delete')),
      ]),
    ]);
  };

  OAuthConnectSettings.prototype.clientForm = function (form, options) {
    return m('form.OAuthConnectForm', { onsubmit: options.onsubmit }, [
      m('.OAuthConnectFormGrid', [
        this.textInput(t('form.name', {}, 'Name'), form, 'name', true),
        this.textInput(t('form.homepage_url', {}, 'Homepage URL'), form, 'homepage_url'),
        this.textInput(t('form.icon_url', {}, 'Icon URL'), form, 'icon_url'),
        m('label', [
          m('span', t('form.enabled', {}, 'Enabled')),
          m('select.FormControl', {
            value: form.is_enabled ? '1' : '0',
            onchange: function (event) {
              form.is_enabled = event.currentTarget.value === '1';
            },
          }, [
            m('option', { value: '1' }, t('status.enabled', {}, 'Enabled')),
            m('option', { value: '0' }, t('status.disabled', {}, 'Disabled')),
          ]),
        ]),
      ]),
      m('label', [
        m('span', t('form.description', {}, 'Description')),
        m('textarea.FormControl', {
          rows: 2,
          value: form.description,
          oninput: function (event) {
            form.description = event.currentTarget.value;
          },
        }),
      ]),
      m('label', [
        m('span', t('form.redirect_uris', {}, 'Redirect URIs')),
        m('textarea.FormControl', {
          rows: 3,
          required: true,
          value: form.redirect_uris,
          placeholder: 'https://app.example.com/oauth/callback',
          oninput: function (event) {
            form.redirect_uris = event.currentTarget.value;
          },
        }),
        m('p.helpText', t('form.redirect_uris_help', {}, 'One exact redirect URI per line. Fragments are not allowed.')),
      ]),
      m('.OAuthConnectScopes', [
        m('span', t('form.allowed_scopes', {}, 'Allowed scopes')),
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
            m('span', scope.key + ' - ' + scopeLabel(scope)),
          ]);
        }),
      ]),
      m('.OAuthConnectFormActions', [
        m('button.Button.Button--primary', {
          type: 'submit',
          disabled: options.loading,
        }, options.loading ? t('form.saving', {}, 'Saving...') : options.submitLabel),
        options.oncancel ? m('button.Button', {
          type: 'button',
          onclick: options.oncancel,
        }, t('actions.cancel', {}, 'Cancel')) : null,
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

    if (!confirm(t('confirm.reset_secret', {}, 'Reset this client secret and revoke existing tokens?'))) return;

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

    if (!confirm(t('confirm.delete_client', {}, 'Delete this client and revoke all of its tokens?'))) return;

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
      m('.OAuthConnectPanelHeader', [
        m('h3', t('authorizations_title', {}, 'Recent authorizations')),
        m('a.Button', {
          href: authorizationsRoute(),
          oncreate: m.route.link,
        }, t('actions.view_all_authorizations', {}, 'View all authorizations')),
      ]),
      self.authorizations.length === 0
        ? m('p.helpText', t('no_authorizations', {}, 'No users have authorized clients yet.'))
        : authorizationTable(self.authorizations, function (authorization) {
          self.revokeAuthorization(authorization);
        }),
    ]);
  };

  OAuthConnectSettings.prototype.revokeAuthorization = function (authorization) {
    var self = this;

    if (!confirm(t('confirm.revoke_authorization', {}, 'Revoke this user authorization and its tokens?'))) return;

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

  function OAuthConnectAuthorizationsPage() {}

  OAuthConnectAuthorizationsPage.prototype.oninit = function () {
    this.loading = true;
    this.loadingAuthorizations = true;
    this.clients = [];
    this.authorizations = [];
    this.meta = {
      page: 1,
      limit: 20,
      total: 0,
      total_pages: 1,
      has_prev: false,
      has_next: false,
    };
    this.error = null;
    this.filters = this.filtersFromRoute();

    if (app.setTitle) {
      app.setTitle(t('authorizations_page_title', {}, 'Authorization records'));
    }

    this.loadClients();
    this.loadAuthorizations();
  };

  OAuthConnectAuthorizationsPage.prototype.filtersFromRoute = function () {
    return {
      page: intParam('page', 1),
      limit: intParam('limit', 20),
      client_id: m.route.param('client_id') || '',
      status: m.route.param('status') || '',
      q: m.route.param('q') || '',
    };
  };

  OAuthConnectAuthorizationsPage.prototype.loadClients = function () {
    var self = this;

    return app.request({
      method: 'GET',
      url: api('/clients'),
    }).then(function (response) {
      self.clients = response.data || [];
      redraw();
    }, function (error) {
      self.error = errorMessage(error);
      redraw();
    });
  };

  OAuthConnectAuthorizationsPage.prototype.loadAuthorizations = function () {
    var self = this;

    self.loadingAuthorizations = true;
    self.error = null;

    return app.request({
      method: 'GET',
      url: authorizationsApi(self.filters),
    }).then(function (response) {
      self.authorizations = response.data || [];
      self.meta = response.meta || self.meta;
    }, function (error) {
      self.error = errorMessage(error);
    }).then(function () {
      self.loading = false;
      self.loadingAuthorizations = false;
      redraw();
    });
  };

  OAuthConnectAuthorizationsPage.prototype.view = function () {
    var self = this;

    return m('.OAuthConnectPage.OAuthConnectAuthorizationPage', [
      m('.container', [
        m('.OAuthConnectPageTitle', [
          m('div', [
            m('h2', t('authorizations_page_title', {}, 'Authorization records')),
            m('p.helpText', t('authorizations_page_description', {}, 'Review, filter, and revoke OAuth2 user authorizations.')),
          ]),
          m('a.Button', {
            href: extensionRoute(),
            oncreate: m.route.link,
          }, t('actions.back_to_settings', {}, 'Back to settings')),
        ]),
        self.error ? m('.Alert.Alert--error', self.error) : null,
        self.filtersPanel(),
        self.recordsPanel(),
      ]),
    ]);
  };

  OAuthConnectAuthorizationsPage.prototype.filtersPanel = function () {
    var self = this;

    return m('.OAuthConnectPanel', [
      m('h3', t('filters.title', {}, 'Filters')),
      m('form.OAuthConnectFilters', {
        onsubmit: function (event) {
          event.preventDefault();
          self.goToPage(1);
        },
      }, [
        m('label', [
          m('span', t('filters.client', {}, 'Client')),
          m('select.FormControl', {
            value: self.filters.client_id,
            onchange: function (event) {
              self.filters.client_id = event.currentTarget.value;
              self.goToPage(1);
            },
          }, [
            m('option', { value: '' }, t('filters.all_clients', {}, 'All clients')),
            self.clients.map(function (client) {
              return m('option', { value: client.client_id }, client.name || client.client_id);
            }),
          ]),
        ]),
        m('label', [
          m('span', t('filters.status', {}, 'Status')),
          m('select.FormControl', {
            value: self.filters.status,
            onchange: function (event) {
              self.filters.status = event.currentTarget.value;
              self.goToPage(1);
            },
          }, [
            m('option', { value: '' }, t('filters.all_statuses', {}, 'All statuses')),
            m('option', { value: 'active' }, t('status.active', {}, 'Active')),
            m('option', { value: 'revoked' }, t('filters.revoked_only', {}, 'Revoked')),
          ]),
        ]),
        m('label', [
          m('span', t('filters.search', {}, 'Search user')),
          m('input.FormControl', {
            type: 'search',
            value: self.filters.q,
            placeholder: t('filters.search_placeholder', {}, 'Username, email, or user ID'),
            oninput: function (event) {
              self.filters.q = event.currentTarget.value;
            },
          }),
        ]),
        m('label', [
          m('span', t('filters.per_page', {}, 'Per page')),
          m('select.FormControl', {
            value: String(self.filters.limit),
            onchange: function (event) {
              self.filters.limit = parseInt(event.currentTarget.value, 10) || 20;
              self.goToPage(1);
            },
          }, [
            m('option', { value: '20' }, '20'),
            m('option', { value: '50' }, '50'),
            m('option', { value: '100' }, '100'),
          ]),
        ]),
        m('.OAuthConnectFilterActions', [
          m('button.Button.Button--primary', { type: 'submit' }, t('actions.apply_filters', {}, 'Apply filters')),
          m('button.Button', {
            type: 'button',
            onclick: function () {
              self.resetFilters();
            },
          }, t('actions.reset_filters', {}, 'Reset')),
          m('button.Button', {
            type: 'button',
            onclick: function () {
              self.loadAuthorizations();
            },
          }, t('actions.refresh', {}, 'Refresh')),
        ]),
      ]),
    ]);
  };

  OAuthConnectAuthorizationsPage.prototype.recordsPanel = function () {
    var self = this;
    var summary = t('pagination.summary', {
      total: self.meta.total || 0,
      page: self.meta.page || 1,
      pages: self.meta.total_pages || 1,
    }, 'Total {total}, page {page} of {pages}');

    return m('.OAuthConnectPanel', [
      m('.OAuthConnectPanelHeader', [
        m('h3', t('authorizations_all_title', {}, 'All authorization records')),
        m('span.helpText', summary),
      ]),
      self.loadingAuthorizations
        ? m('.OAuthConnectLoading', LoadingIndicator ? m(LoadingIndicator) : t('loading', {}, 'Loading...'))
        : self.authorizations.length === 0
          ? m('p.helpText', t('no_authorizations_filtered', {}, 'No authorization records match the current filters.'))
          : authorizationTable(self.authorizations, function (authorization) {
            self.revokeAuthorization(authorization);
          }),
      self.pagination(),
    ]);
  };

  OAuthConnectAuthorizationsPage.prototype.pagination = function () {
    var self = this;

    return m('.OAuthConnectPagination', [
      m('button.Button', {
        type: 'button',
        disabled: !self.meta.has_prev || self.loadingAuthorizations,
        onclick: function () {
          self.goToPage(1);
        },
      }, t('pagination.first', {}, 'First')),
      m('button.Button', {
        type: 'button',
        disabled: !self.meta.has_prev || self.loadingAuthorizations,
        onclick: function () {
          self.goToPage((self.meta.page || 1) - 1);
        },
      }, t('pagination.prev', {}, 'Previous')),
      m('span.OAuthConnectPageCounter', t('pagination.page_counter', {
        page: self.meta.page || 1,
        pages: self.meta.total_pages || 1,
      }, 'Page {page} / {pages}')),
      m('button.Button', {
        type: 'button',
        disabled: !self.meta.has_next || self.loadingAuthorizations,
        onclick: function () {
          self.goToPage((self.meta.page || 1) + 1);
        },
      }, t('pagination.next', {}, 'Next')),
      m('button.Button', {
        type: 'button',
        disabled: !self.meta.has_next || self.loadingAuthorizations,
        onclick: function () {
          self.goToPage(self.meta.total_pages || 1);
        },
      }, t('pagination.last', {}, 'Last')),
    ]);
  };

  OAuthConnectAuthorizationsPage.prototype.goToPage = function (page) {
    this.filters.page = Math.max(1, page);
    setRoute(authorizationsRoute(this.filters));
  };

  OAuthConnectAuthorizationsPage.prototype.resetFilters = function () {
    this.filters = {
      page: 1,
      limit: 20,
      client_id: '',
      status: '',
      q: '',
    };
    setRoute(authorizationsRoute(this.filters));
  };

  OAuthConnectAuthorizationsPage.prototype.revokeAuthorization = function (authorization) {
    var self = this;

    if (!confirm(t('confirm.revoke_authorization', {}, 'Revoke this user authorization and its tokens?'))) return;

    app.request({
      method: 'POST',
      url: api('/authorizations/revoke'),
      body: {
        client_id: authorization.client_id,
        user_id: authorization.user_id,
      },
    }).then(function () {
      return self.loadAuthorizations();
    }, function (error) {
      self.error = errorMessage(error);
      redraw();
    });
  };

  app.initializers.add('iseekup/oauth-connect', function () {
    try {
      localizeExtensionMetadata();

      app.routes.oauthConnectAuthorizations = {
        path: '/oauth-connect/authorizations',
        component: OAuthConnectAuthorizationsPage,
      };

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
