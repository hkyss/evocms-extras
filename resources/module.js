const Module = class {
  constructor() {
    this.adminUrl = '/api/v1/extras/admin';
    this.statusTimer = null;
    this.installed = null;
    this.catalog = null;
    this.busy = false;

    this.groups = {
      manifest: 'core/custom/composer.json',
      composer: 'composer',
      provider: 'провайдеры',
      file: 'файлы',
      element: 'элементы',
      sql: 'база данных',
      record: 'запись об установке',
    };

    this.forges = {
      'github.com': 'fa-github',
      'gitlab.com': 'fa-gitlab',
      'bitbucket.org': 'fa-bitbucket',
    };

    this.compatibility = {
      verified: { label: 'проверено', level: 'ok' },
      unknown: { label: 'не проверено', level: 'warning' },
      incompatible: { label: 'несовместимо', level: 'error' },
    };

    $(document).ajaxStart(() => $('#mainloader').addClass('show'));
    $(document).ajaxStop(() => $('#mainloader').removeClass('show'));
  }

  init() {
    this.loadInstalled();
  }

  reload() {
    this.loadInstalled();

    if (this.catalog !== null) {
      this.loadCatalog();
    }
  }

  openCatalog() {
    if (this.catalog === null) {
      this.loadCatalog();
    }
  }


  loadInstalled() {
    $.ajax({
      url: `${this.adminUrl}/installed`,
      dataType: 'json',
      type: 'GET',
      success: (response) => {
        this.installed = response?.data?.extras || [];
        this.fillInstalled();
      },
      error: (xhr) => {
        this.fillEmpty('#installedTable', 6, 'Список не загрузился');
        this.showStatus(this.errorText(xhr), true);
      },
    });
  }

  fillInstalled() {
    if (this.installed.length === 0) {
      this.fillEmpty('#installedTable', 6, 'Через этот модуль ничего не установлено');
      return;
    }

    document.querySelector('#installedTable').innerHTML =
      this.installed.map((extra) => this.installedRow(extra)).join('');
  }

  installedRow(extra) {
    return `<tr data-extra="${this.text(extra.coordinate)}">
        <td>${this.packageCell(extra)}</td>
        <td>${this.formatBadge(extra)}</td>
        <td>
          ${this.text(extra.version || 'установлено')}
          ${this.constraintNote(extra)}
        </td>
        <td>${this.compatibilityBadge(extra)}</td>
        <td class="module__grid-detail">${this.detailCell(extra)}</td>
        <td>${this.actionsCell(extra, 'installedTable')}</td>
      </tr>`;
  }


  loadCatalog() {
    $.ajax({
      url: `${this.adminUrl}/catalog`,
      dataType: 'json',
      type: 'GET',
      success: (response) => {
        this.catalog = response?.data?.extras || [];
        this.fillProblems(response?.data?.problems || {});
        this.filterCatalog();
      },
      error: (xhr) => {
        this.fillEmpty('#catalogTable', 6, 'Каталог не загрузился');
        this.showStatus(this.errorText(xhr), true);
      },
    });
  }

  fillProblems(problems) {
    const lines = Object.entries(problems).map(([source, reason]) => `${source}: ${reason}`);

    document.querySelector('#catalogProblems').innerHTML = lines.length === 0
      ? ''
      : `<div class="alert alert-warning m-0">${this.text(lines.join(' '))}</div>`;
  }

  filterCatalog() {
    if (this.catalog === null) {
      return;
    }

    const needle = document.querySelector('#catalog_search').value.trim().toLowerCase();
    const state = document.querySelector('#catalog_state').value;
    const format = document.querySelector('#catalog_format').value;

    const matched = this.catalog.filter((extra) => {
      if (state === 'installed' && !extra.installed) return false;
      if (state === 'absent' && extra.installed) return false;
      if (format !== '' && extra.format !== format) return false;
      if (needle === '') return true;

      return `${extra.coordinate} ${extra.title} ${extra.description}`.toLowerCase().includes(needle);
    });

    document.querySelector('#catalogSummary').textContent = `${matched.length} из ${this.catalog.length}`;

    if (matched.length === 0) {
      this.fillEmpty('#catalogTable', 6, 'Ничего не нашлось');
      return;
    }

    document.querySelector('#catalogTable').innerHTML = matched.map((extra) => this.catalogRow(extra)).join('');
  }

  catalogRow(extra) {
    return `<tr data-extra="${this.text(extra.coordinate)}">
        <td>${this.packageCell(extra)}</td>
        <td>${this.formatBadge(extra)}</td>
        <td>${this.text(extra.latest || '—')}</td>
        <td>${this.compatibilityBadge(extra)}${extra.listed ? '' : '<small class="module__grid-note">нет в каталоге</small>'}</td>
        <td class="module__grid-detail">${this.stateCell(extra)}</td>
        <td>${this.actionsCell(extra, 'catalogTable')}</td>
      </tr>`;
  }


  packageCell(extra) {
    const coordinate = String(extra.coordinate);
    const named = extra.title && extra.title !== coordinate;
    const name = named ? extra.title : coordinate;
    const link = extra.homepage
      ? `<a href="${this.attr(extra.homepage)}" target="_blank" rel="noopener">${this.text(name)}</a>`
      : this.text(name);

    return `${link}${this.repositoryLink(extra)}
      ${named ? `<small class="module__grid-note">${this.text(coordinate)}</small>` : ''}
      ${extra.description ? `<small class="module__grid-note">${this.text(extra.description)}</small>` : ''}`;
  }

  repositoryLink(extra) {
    if (!extra.repository) {
      return '';
    }

    const host = String(extra.repository).replace(/^https?:\/\//, '').split('/')[0].toLowerCase();

    return ` <a class="module__grid-repo" href="${this.attr(extra.repository)}" target="_blank" rel="noopener"
        title="${this.attr(extra.repository)}"><i class="fa ${this.forges[host] || 'fa-code-fork'}"></i></a>`;
  }

  formatBadge(extra) {
    return `<span class="module__badge _${this.text(extra.format)}">${this.text(extra.format)}</span>`;
  }

  compatibilityBadge(extra) {
    const status = this.compatibility[extra.compatibility] || this.compatibility.unknown;

    return `<span class="module__badge _${status.level}">${this.text(status.label)}</span>`;
  }

  constraintNote(extra) {
    if (!extra.constraint || extra.constraint === extra.version) {
      return '';
    }

    return `<small class="module__grid-note">в манифесте ${this.text(extra.constraint)}</small>`;
  }

  stateCell(extra) {
    if (!extra.installed) {
      return '<span class="module__muted">—</span>';
    }

    return `<span class="module__badge _ok">${this.text(extra.version || 'установлено')}</span>${this.constraintNote(extra)}`;
  }

  detailCell(extra) {
    if (extra.format !== 'legacy') {
      return extra.type ? this.text(extra.type) : '<span class="module__muted">—</span>';
    }

    const counted = [];

    if (extra.files) counted.push(`${this.number(extra.files)} ${this.plural(extra.files, 'файл', 'файла', 'файлов')}`);
    if (extra.elements) counted.push(`${this.number(extra.elements)} ${this.plural(extra.elements, 'элемент', 'элемента', 'элементов')}`);

    const at = this.moment(extra.installed_at);

    if (counted.length === 0 && at === '') {
      return '<span class="module__muted">—</span>';
    }

    return `${this.text(counted.join(', '))}${at === '' ? '' : `<small class="module__grid-note">${this.text(at)}</small>`}`;
  }

  actionsCell(extra, table) {
    const coordinate = this.attr(extra.coordinate);
    const button = extra.installed
      ? `<button class="module__table-button _delete" type="button" title="Удалить из системы"
                onclick="module.askPlan('${coordinate}', 'remove', '', '${table}')">
           <i class="fa fa-trash"></i>
         </button>`
      : `<button class="module__table-button _install" type="button" title="Установить"
                onclick="module.askPlan('${coordinate}', 'install', '', '${table}')">
           <i class="fa fa-download"></i>
         </button>`;

    return `<div class="module__table-actions">${button}</div>`;
  }


  askPlan(coordinate, intent, version, table) {
    if (this.busy) return;

    this.closePlan();

    $.ajax({
      url: `${this.adminUrl}/extras/${coordinate}/plan`,
      data: { intent, version },
      dataType: 'json',
      type: 'GET',
      success: (response) => this.showPlan(coordinate, response?.data || {}, table),
      error: (xhr) => this.showStatus(this.errorText(xhr), true),
    });
  }

  showPlan(coordinate, plan, table) {
    const row = document.querySelector(`#${table} [data-extra="${coordinate}"]`);
    if (row === null) return;

    const panel = document.createElement('tr');
    panel.className = 'module__plan-row';
    panel.innerHTML = `<td colspan="6">${this.planHtml(coordinate, plan, table)}</td>`;

    row.after(panel);
    panel.scrollIntoView({ block: 'nearest' });
  }

  planHtml(coordinate, plan, table) {
    const install = plan.intent !== 'remove';
    const forbidden = plan.forbidden || [];
    const blockers = plan.blockers || [];

    const alerts = []
      .concat(forbidden, blockers)
      .map((reason) => `<div class="alert alert-danger m-0">${this.text(reason)}</div>`)
      .concat((plan.warnings || []).map((reason) => `<div class="alert alert-warning m-0">${this.text(reason)}</div>`))
      .join('');

    const doable = forbidden.length === 0 && plan.empty !== true;
    const gated = doable && install && blockers.length > 0;

    return `<div class="module__plan ${install ? '_install' : '_remove'}">
        <div class="module__plan-head">
          <div class="module__plan-title">
            ${install ? 'Установка' : 'Удаление'} <b>${this.text(coordinate)}</b>
            ${plan.to || plan.from ? this.text(plan.to || plan.from) : ''} — что произойдёт
          </div>
          ${install ? this.versionPicker(coordinate, plan, table) : ''}
        </div>

        <div class="module__plan-body">
          ${alerts}
          ${plan.empty && forbidden.length === 0 ? '<div class="alert alert-primary m-0">Делать нечего.</div>' : ''}
          ${this.stepsHtml(plan.steps || [])}
        </div>

        <div class="module__plan-footer">
          ${this.planHint(plan, install)}
          ${gated ? `<label class="module__plan-force">
              <input type="checkbox" id="planForce" onchange="module.toggleForce(this)"> Всё равно установить
            </label>` : ''}
          ${doable ? `<button class="btn ${install ? 'btn-primary' : 'btn-danger'}" type="button" id="planApply"
                    ${gated ? 'disabled' : ''}
                    onclick="module.applyPlan('${this.attr(coordinate)}', '${install ? 'install' : 'remove'}', this)">
              <i class="fa fa-${install ? 'download' : 'trash'}"></i> ${install ? 'Установить' : 'Удалить'}
            </button>` : ''}
          <button class="btn btn-secondary" type="button" onclick="module.closePlan()">Отмена</button>
        </div>
      </div>`;
  }

  planHint(plan, install) {
    if (plan.format === 'composer') {
      return '<small class="module__hint">Composer пересобирает дерево зависимостей — это занимает минуту-другую.</small>';
    }

    return install
      ? '<small class="module__hint">Файлы, которые уже есть, сохраняются рядом с суффиксом <code>.old</code>.</small>'
      : '<small class="module__hint">Схема базы не откатывается: у формата нет обратных миграций.</small>';
  }

  versionPicker(coordinate, plan, table) {
    const versions = plan.versions || [];

    if (versions.length < 2) {
      return '';
    }

    const options = versions
      .map((version) => `<option value="${this.text(version)}"${version === plan.to ? ' selected' : ''}>${this.text(version)}</option>`)
      .join('');

    return `<div class="module__plan-version">
        <label for="planVersion">Версия</label>
        <select id="planVersion" class="inputBox"
                onchange="module.askPlan('${this.attr(coordinate)}', 'install', this.value, '${table}')">${options}</select>
      </div>`;
  }

  stepsHtml(steps) {
    if (steps.length === 0) {
      return '';
    }

    const grouped = {};

    steps.forEach((step) => {
      (grouped[step.group] = grouped[step.group] || []).push(step);
    });

    return `<div class="module__plan-groups">${Object.entries(grouped).map(([group, entries]) => `
        <div class="module__plan-group">
          <div class="module__plan-group-title">${this.text(this.groups[group] || group)}</div>
          <ul class="module__plan-steps">
            ${entries.map((step) => `<li class="${step.mutates ? '' : '_kept'}">${this.text(step.summary)}</li>`).join('')}
          </ul>
        </div>`).join('')}</div>`;
  }

  toggleForce(box) {
    const button = document.querySelector('#planApply');

    if (button !== null) {
      button.disabled = !box.checked;
    }
  }

  closePlan() {
    document.querySelectorAll('.module__plan-row').forEach((panel) => panel.remove());
  }

  applyPlan(coordinate, intent, button) {
    if (this.busy) return;

    const install = intent === 'install';
    const version = document.querySelector('#planVersion');
    const force = document.querySelector('#planForce');

    this.busy = true;
    button.disabled = true;
    button.innerHTML = `<i class="fa fa-spinner fa-spin"></i> ${install ? 'Устанавливаем…' : 'Удаляем…'}`;

    $.ajax({
      url: `${this.adminUrl}/extras/${coordinate}`,
      data: install ? { version: version === null ? '' : version.value, force: force !== null && force.checked ? 1 : 0 } : {},
      dataType: 'json',
      type: install ? 'POST' : 'DELETE',
      success: (response) => {
        this.busy = false;
        this.closePlan();
        this.showStatus(response?.data?.message || (install ? 'Пакет установлен' : 'Пакет удалён'));
        this.showNotes(response?.data);
        this.reload();
      },
      error: (xhr) => {
        this.busy = false;
        button.disabled = false;
        button.innerHTML = `<i class="fa fa-${install ? 'download' : 'trash'}"></i> ${install ? 'Установить' : 'Удалить'}`;
        this.showStatus(this.errorText(xhr), true);
        this.showNotes(xhr?.responseJSON?.data);
      },
    });
  }

  showNotes(data) {
    const lines = [].concat(data?.notes || [], data?.output || []);

    if (lines.length === 0) {
      return;
    }

    document.querySelector('#moduleStatus').insertAdjacentHTML(
      'beforeend',
      `<pre class="module__log">${this.text(lines.join('\n'))}</pre>`
    );
  }


  fillEmpty(selector, columns, message) {
    document.querySelector(selector).innerHTML =
      `<tr><td colspan="${columns}" class="module__table-empty">${this.text(message)}</td></tr>`;
  }

  showStatus(message, isError = false) {
    const box = document.querySelector('#moduleStatus');

    box.className = `alert ${isError ? 'alert-danger' : 'alert-success'}`;
    box.textContent = message;
    box.hidden = false;

    window.clearTimeout(this.statusTimer);

    if (!isError) {
      this.statusTimer = window.setTimeout(() => {
        box.hidden = true;
      }, 5000);
    }
  }

  errorText(xhr) {
    return this.messages(xhr?.responseJSON?.errors);
  }

  messages(errors) {
    const list = Object.values(errors || {}).flat();

    return list.length > 0 ? list.join(' ') : 'Не удалось выполнить запрос.';
  }

  plural(count, one, few, many) {
    const n = Math.abs(Number(count)) % 100;
    const tail = n % 10;

    if (n > 10 && n < 20) return many;
    if (tail > 1 && tail < 5) return few;
    if (tail === 1) return one;

    return many;
  }

  number(value) {
    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed.toLocaleString('ru-RU') : '0';
  }

  text(value) {
    return $('<div>').text(value ?? '').html();
  }

  attr(value) {
    return this.text(value).replace(/'/g, '&#39;').replace(/"/g, '&quot;');
  }

  /** The server hands times over in iso: the timezone is the one the manager is sitting in. */
  moment(value) {
    const at = new Date(value ?? '');

    if (Number.isNaN(at.getTime())) {
      return '';
    }

    return at.toLocaleString('ru-RU', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    }).replace(',', '');
  }
};
