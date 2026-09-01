@php($assets = \hkyss\Extras\Manager\ManagerModule::inline())
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Extras</title>

    <link rel="stylesheet" href="media/style/default/css/styles.min.css">
    <style>{!! $assets['css'] !!}</style>

    <script type="text/javascript" src="media/script/tabpane.js"></script>
    <script type="text/javascript" src="media/script/jquery/jquery.min.js"></script>

    <script>{!! $assets['js'] !!}</script>
    <script>
      const module = new Module();
    </script>
</head>

<body class="module">
<div id="mainloader"></div>

<h1 class="pagetitle">
        <span class="pagetitle-icon">
            <i class="fa fa-cubes"></i>
        </span>
    <span class="pagetitle-text">
            Extras
        </span>
</h1>

<div id="actions">
    <ul class="btn-group">
        <li>
            <a class="btn btn-secondary" href="#" onclick="module.reload(); return false;">
                <i class="fa fa-refresh"></i> Обновить
            </a>
        </li>
        <li>
            <a class="btn btn-secondary" href="#" onclick="document.location.href='index.php?a=106';">Закрыть модуль</a>
        </li>
    </ul>
</div>

<div class="sectionBody">
    <div id="moduleStatus" hidden></div>

    <div class="tab-pane" id="documentPane">
        <script type="text/javascript">
          var tpSettings = new WebFXTabPane(document.getElementById("documentPane"), true);
        </script>

        <div class="tab-page" id="tabInstalled">
            <h2 class="tab">Установленные</h2>
            <script type="text/javascript">
              tpSettings.addTabPage(document.getElementById("tabInstalled"));
            </script>

            <div class="module__tab">
                <div class="module__table">
                    <table class="grid module__grid">
                        <thead>
                        <tr>
                            <th>Пакет</th>
                            <th>Формат</th>
                            <th>Версия</th>
                            <th>Evo 3</th>
                            <th>Установлено</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody id="installedTable">
                        <tr>
                            <td colspan="6" class="module__table-empty">Читаем, что установлено…</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-page" id="tabCatalog">
            <h2 class="tab">Каталог</h2>
            <script type="text/javascript">
              tpSettings.addTabPage(document.getElementById("tabCatalog"), () => module.openCatalog());
            </script>

            <div class="module__tab">
                <div class="module__search">
                    <div class="module__search-group">
                        <label for="catalog_search">Поиск</label>
                        <input id="catalog_search" class="inputBox" type="text"
                               placeholder="Имя, заголовок или описание"
                               onkeyup="module.filterCatalog()">
                    </div>
                    <div class="module__search-group">
                        <label for="catalog_state">Состояние</label>
                        <select id="catalog_state" class="inputBox" onchange="module.filterCatalog()">
                            <option value="">Все</option>
                            <option value="absent">Не установленные</option>
                            <option value="installed">Установленные</option>
                        </select>
                    </div>
                    <div class="module__search-group">
                        <label for="catalog_format">Формат</label>
                        <select id="catalog_format" class="inputBox" onchange="module.filterCatalog()">
                            <option value="">Любой</option>
                            <option value="composer">composer</option>
                            <option value="legacy">legacy</option>
                        </select>
                    </div>
                    <div class="module__search-buttons">
                        <small id="catalogSummary" class="module__hint"></small>
                    </div>
                </div>

                <div id="catalogProblems"></div>

                <div class="module__table">
                    <table class="grid module__grid">
                        <thead>
                        <tr>
                            <th>Пакет</th>
                            <th>Формат</th>
                            <th>Версия</th>
                            <th>Evo 3</th>
                            <th>Состояние</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody id="catalogTable">
                        <tr>
                            <td colspan="6" class="module__table-empty">Откройте вкладку, чтобы загрузить каталог</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
  $(window).on('load', () => module.init());
</script>
</body>

</html>
