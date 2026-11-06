"""Найти мёртвые модели в app/Models через достижимость от внешних ссылок.

Наивный grep даёт ложные «мёртвые»: две мёртвые модели могут ссылаться друг на
друга (`hasMany(Other::class)`), и каждая выглядит используемой. Поэтому строим
граф: корни — модели, на которые ссылается хоть один файл ВНЕ app/Models;
дальше замыкание по ссылкам между моделями. Что не достигнуто — мёртвое.
"""
import os
import re
import sys
import json

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
MODELS_DIR = os.path.join(ROOT, 'app', 'Models')

SCAN_DIRS = ['app', 'tests', 'tools', 'config', 'database', 'routes', 'bootstrap', 'resources']
SKIP_DIRS = {'vendor', 'node_modules', 'storage', 'dist', '.git', '.shots', '.tmp-hall', 'outputs'}


def php_files():
    out = []
    for d in SCAN_DIRS:
        base = os.path.join(ROOT, d)
        if not os.path.isdir(base):
            continue
        for dirpath, dirnames, filenames in os.walk(base):
            dirnames[:] = [x for x in dirnames if x not in SKIP_DIRS]
            for fn in filenames:
                if fn.endswith('.php'):
                    out.append(os.path.join(dirpath, fn))
    return out


def model_classes():
    """short name -> FQCN для всех файлов app/Models."""
    res = {}
    for fn in sorted(os.listdir(MODELS_DIR)):
        if not fn.endswith('.php'):
            continue
        path = os.path.join(MODELS_DIR, fn)
        src = open(path, encoding='utf-8', errors='replace').read()
        m = re.search(r'^namespace\s+([\w\\]+);', src, re.M)
        ns = m.group(1) if m else 'App'
        cm = re.search(r'^(?:final\s+|abstract\s+)?class\s+(\w+)', src, re.M)
        if not cm:
            continue
        short = cm.group(1)
        res[short] = (ns + '\\' + short, path)
    return res


def main():
    models = model_classes()
    fqcn_to_short = {v[0]: k for k, v in models.items()}

    # Для каждого файла: какие FQCN моделей он упоминает.
    refs = {}          # file -> set(short)
    outside_roots = set()
    edges = {short: set() for short in models}

    for path in php_files():
        try:
            src = open(path, encoding='utf-8', errors='replace').read()
        except OSError:
            continue

        used = set()

        # `use App\Models\X;` и `use App\Models\X as Y;`
        for m in re.finditer(r'^use\s+([\w\\]+)(?:\s+as\s+(\w+))?\s*;', src, re.M):
            fq, alias = m.group(1), m.group(2)
            if fq in fqcn_to_short:
                used.add(fqcn_to_short[fq])
                # короткое имя в теле файла тоже считаем ссылкой
                used.add(fqcn_to_short[fq])

        # полные имена в теле: \App\Models\X::class и т.п. Ведущий слэш
        # необязателен и НЕ должен ломать совпадение — `(new \App\Models\X())`
        # пишется именно так, и первая версия этого шаблона его пропускала,
        # объявляя живые модели мёртвыми.
        for fq, short in fqcn_to_short.items():
            if re.search(r'(?<![\w])\\{0,1}' + re.escape(fq) + r'\b', src):
                used.add(short)

        # Внутри app/Models импорт не нужен: соседняя модель зовётся по короткому
        # имени. Без этого правила `hasMany(EventDate::class)` в живой модели не
        # создаёт ребро, и мёртвый EventDate уезжает в удаление вместе с живым
        # кодом, который на него ссылается.
        if os.path.abspath(os.path.dirname(path)) == os.path.abspath(MODELS_DIR):
            for short in models:
                if re.search(r'(?<![\w$])' + re.escape(short) + r'\b', src):
                    used.add(short)

        if not used:
            continue

        refs[path] = used
        inside = os.path.abspath(os.path.dirname(path)) == os.path.abspath(MODELS_DIR)
        if inside:
            # какая модель объявлена в этом файле — ребро к остальным
            for short, (fq, mp) in models.items():
                if os.path.abspath(mp) == os.path.abspath(path):
                    edges[short] |= (used - {short})
                    break
        else:
            outside_roots |= used

    # Замыкание от внешних корней.
    live = set(outside_roots)
    stack = list(outside_roots)
    while stack:
        cur = stack.pop()
        for nxt in edges.get(cur, ()):
            if nxt not in live:
                live.add(nxt)
                stack.append(nxt)

    dead = sorted(set(models) - live)

    print('моделей всего:', len(models))
    print('живых:', len(live))
    print('мёртвых:', len(dead))
    print()
    for short in dead:
        fq, path = models[short]
        print('  ', short)

    json.dump(
        {'dead': [{'short': s, 'fqcn': models[s][0], 'path': models[s][1]} for s in dead],
         'live': sorted(live)},
        open(os.path.join(ROOT, '.tmp-hall', 'dead-models.json'), 'w', encoding='utf-8'),
        ensure_ascii=False, indent=1)


if __name__ == '__main__':
    main()
