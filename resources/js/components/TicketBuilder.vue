<script setup>
import { computed, ref, onMounted, watch } from 'vue';
// Раньше здесь был `axios` напрямую: он не несёт Authorization-заголовок
// (интерсепторов в проекте нет) и обращался к `/api/ticket-templates` без
// префикса версии, то есть сохранение шаблона уходило в 401/404. Теперь —
// общий клиент `@/lib/api`, который и токен подставляет, и разбирает §66-конверт.
import { get, send, ApiError } from '@/lib/api';
import {
    CANVAS_PRESETS,
    DEFAULT_TICKET_VARIABLES,
    FONT_STACKS,
    applyCornerResize,
    buildTemplatePayload,
    cloneElement,
    elementLayerLabel,
    elementTypeLabel,
    ensureElementDefaults,
    moveElementInList,
    normalizeNewElement,
    resizeHandlesFor,
    syncDerivedCoordinates,
    duplicateAsElement,
    renderTextContent as renderVars,
} from '@/lib/ticketBuilder';
// Фильтр «только картинки» для пикера из медиатеки — тот же классификатор,
// что в разделе «Файлы» (см. lib/mediaLibrary.ts).
import { kindOf, storageSrc } from '@/lib/mediaLibrary';
// Тема — из общего стора (класс `dark` на <html>): конструктор живёт внутри
// тёмной админки, поэтому тёмное оформление у него базовое, а светлое —
// опциональный класс `.builder-light`.
import { useUiStore } from '@/stores/ui';
// QR рисуется ЛОКАЛЬНО (@/lib/qr). Раньше здесь стояла ссылка на внешний
// api.qrserver.com, которая отправляла подписанный `qr_payload` билета третьей
// стороне вместе с IP покупателя — см. комментарий в начале lib/qr.ts.
import { QR_PREVIEW_FALLBACK, renderQrDataUrl } from '@/lib/qr';

const props = defineProps({
    templateId: {
        type: [Number, String],
        default: null
    },
    // Оставлен для обратной совместимости с вызывающим кодом, но в payload
    // НЕ уходит: организацию сервер берёт из токена (см. buildTemplatePayload).
    organizationId: {
        type: [Number, String],
        default: null
    }
});

const emit = defineEmits(['template-saved', 'template-deleted']);

// Тема — из общего стора (класс `dark` на <html>): конструктор живёт внутри
// тёмной админки, поэтому тёмное оформление у него базовое, а светлое —
// опциональный класс `.builder-light`.
const ui = useUiStore();

// Состояние редактора
const activeTab = ref('elements');
const zoom = ref(100);
const selectedElement = ref(null);
const isDragging = ref(false);
const dragOffset = ref({ x: 0, y: 0 });
const showTemplateModal = ref(false);
const templateName = ref('');
const selectedTemplate = ref(null);

// Состояние обращения к API: занятость кнопок и текст ошибки.
const saving = ref(false);
const loading = ref(false);
const deleting = ref(false);
const loadError = ref(null);
// Имя открытого шаблона: подставляется в модалку сохранения, чтобы при
// обновлении не печатать название заново.
const currentTemplateName = ref('');

// Холст по умолчанию — вертикальный мобильный: payload всегда шлёт
// `format: 'mobile'`, а билет смотрят с телефона. При открытии сохранённого
// шаблона размеры и фон подменяются из `template_json` (см. loadTemplate).
// Заготовки рисовались под 600×400 — их применение размер возвращает.
const canvasWidth = ref(400);
const canvasHeight = ref(600);
const canvasBackground = ref('#ffffff');

// Id открытой записи. НЕ props.templateId напрямую: после создания нового
// шаблона POST'ом проп остаётся null, и повторное «Сохранить» плодило бы
// вторую запись вместо обновления только что созданной.
const internalId = ref(props.templateId ?? null);

// Предпросмотр: прячет панели, показывает билет как его увидит покупатель.
const previewMode = ref(false);
// Тестовые данные в предпросмотре: подстановка {{...}} или сырые плейсхолдеры.
const showSampleData = ref(true);

// Пикер изображений из медиатеки (для элемента-изображения).
const mediaPickerOpen = ref(false);
const mediaItems = ref([]);
const mediaLoading = ref(false);
const mediaError = ref(null);

// Ключ пресета, совпадающего с текущим размером холста, — для подсветки
// активного пункта в select. Нестандартный размер даёт ''.
const presetKey = computed(() => {
    for (const [key, size] of Object.entries(CANVAS_PRESETS)) {
        if (size.width === canvasWidth.value && size.height === canvasHeight.value) {
            return key;
        }
    }
    return '';
});

// Только картинки из загруженного списка медиатеки.
const mediaImages = computed(() =>
    mediaItems.value.filter((asset) => kindOf(asset?.mime_type) === 'image')
);

// Фон холста: сплошной цвет и/или линейный градиент («текстура»).
// Раньше `saveTemplate()` жёстко подставлял `backgroundColor: '#ffffff'`,
// затирая реальный фон макета. Теперь фон — это состояние редактора.
const useGradient = ref(false);
const gradientFrom = ref('#0f766e');
const gradientTo = ref('#1e293b');
const gradientDir = ref('to bottom');

const canvasBackgroundStyle = computed(() =>
  useGradient.value
    ? `linear-gradient(${gradientDir.value}, ${gradientFrom.value}, ${gradientTo.value})`
    : canvasBackground.value,
);

const canvasGradientConfig = computed(() =>
  useGradient.value
    ? { from: gradientFrom.value, to: gradientTo.value, direction: gradientDir.value }
    : null,
);

// Элементы на холсте
const elements = ref([]);

// Готовые шаблоны, загруженные из БД (GET /ticket-templates).
//
// Раньше здесь лежал захардкоженный массив из пяти «шаблонов» — они выглядели
// как сохранённые, но жили только в памяти вкладки: выбрать их было можно, а
// сохранить — нет, потому что `template_json` этих объектов никуда не уходил.
// Теперь список приходит из БД, а прежние макеты стали стартовыми заготовками
// (BUILT_IN_LAYOUTS ниже) — из них начинают новый шаблон.
const templates = ref([]);
const templatesLoading = ref(false);
const templatesError = ref(null);

async function loadTemplates() {
    templatesLoading.value = true;
    templatesError.value = null;
    try {
        const res = await get('/ticket-templates');
        const payload = res.data;
        templates.value = Array.isArray(payload) ? payload : (payload?.data ?? []);
    } catch (e) {
        templatesError.value = e instanceof Error ? e.message : String(e);
        templates.value = [];
    } finally {
        templatesLoading.value = false;
    }
}

// Стартовые заготовки макета.
//
// Это НЕ сохранённые шаблоны: они не имеют id и не приходят с сервера. Из них
// начинают новый шаблон — так администратору не нужно рисовать билет с нуля.
// Заготовка «переменная: …» использует синтаксис {{...}}, который понимает
// renderTextContent().
const BUILT_IN_LAYOUTS = [
    {
        key: 'classic-concert',
        name: 'Классический концерт',
        config: {
            backgroundColor: '#1a1a2e',
            elements: [
                { id: 1, type: 'rectangle', x: 0, y: 0, width: 600, height: 400, fill: '#1a1a2e' },
                { id: 2, type: 'text', x: 50, y: 50, content: '{{event.name}}', fontSize: 32, fontWeight: 'bold', color: '#ffffff' },
                { id: 3, type: 'text', x: 50, y: 100, content: '{{event.date}}', fontSize: 18, color: '#e94560' },
                { id: 4, type: 'text', x: 50, y: 150, content: '{{event.venue}}', fontSize: 16, color: '#cccccc' },
                { id: 5, type: 'qr', x: 400, y: 250, size: 120 },
                { id: 6, type: 'text', x: 50, y: 350, content: '{{seat.info}}', fontSize: 14, color: '#ffffff' }
            ]
        }
    },
    {
        key: 'minimal',
        name: 'Минимализм',
        config: {
            backgroundColor: '#ffffff',
            elements: [
                { id: 1, type: 'rectangle', x: 0, y: 0, width: 600, height: 400, fill: '#ffffff' },
                { id: 2, type: 'line', x1: 0, y1: 80, x2: 600, y2: 80, stroke: '#000000', strokeWidth: 2 },
                { id: 3, type: 'text', x: 40, y: 40, content: '{{event.name}}', fontSize: 28, fontWeight: 'bold', color: '#000000' },
                { id: 4, type: 'text', x: 40, y: 120, content: '{{event.name}}', fontSize: 16, color: '#333333' },
                { id: 5, type: 'text', x: 40, y: 160, content: '{{event.date}}', fontSize: 14, color: '#666666' },
                { id: 6, type: 'qr', x: 420, y: 200, size: 150 },
                { id: 7, type: 'text', x: 40, y: 350, content: '{{ticket.holder}}', fontSize: 18, color: '#000000' }
            ]
        }
    },
    {
        key: 'festival',
        name: 'Фестиваль',
        config: {
            backgroundColor: '#ff6b6b',
            elements: [
                { id: 1, type: 'rectangle', x: 0, y: 0, width: 600, height: 400, fill: '#ff6b6b' },
                { id: 2, type: 'circle', cx: 500, cy: 80, r: 60, fill: '#feca57' },
                { id: 3, type: 'text', x: 30, y: 60, content: '{{event.name}}', fontSize: 36, fontWeight: 'bold', color: '#ffffff' },
                { id: 4, type: 'text', x: 30, y: 100, content: '{{event.date}}', fontSize: 20, color: '#feca57' },
                { id: 5, type: 'text', x: 30, y: 200, content: '{{event.name}}', fontSize: 20, color: '#ffffff' },
                { id: 6, type: 'qr', x: 380, y: 220, size: 140 },
                { id: 7, type: 'text', x: 30, y: 350, content: '{{ticket.number}}', fontSize: 14, color: '#ffffff' }
            ]
        }
    },
    {
        key: 'theater',
        name: 'Театральный',
        config: {
            backgroundColor: '#2c3e50',
            elements: [
                { id: 1, type: 'rectangle', x: 0, y: 0, width: 600, height: 400, fill: '#2c3e50' },
                { id: 2, type: 'rectangle', x: 20, y: 20, width: 560, height: 360, fill: 'transparent', stroke: '#d4af37', strokeWidth: 3 },
                { id: 3, type: 'text', x: 50, y: 80, content: '{{event.name}}', fontSize: 24, fontWeight: 'bold', color: '#d4af37' },
                { id: 4, type: 'text', x: 50, y: 130, content: '{{event.name}}', fontSize: 22, color: '#ecf0f1' },
                { id: 5, type: 'text', x: 50, y: 180, content: '{{event.venue}}', fontSize: 16, color: '#bdc3c7' },
                { id: 6, type: 'qr', x: 400, y: 240, size: 130 },
                { id: 7, type: 'text', x: 50, y: 350, content: '{{seat.info}}', fontSize: 14, color: '#ecf0f1' }
            ]
        }
    },
    {
        key: 'business',
        name: 'Деловой',
        config: {
            backgroundColor: '#f8f9fa',
            elements: [
                { id: 1, type: 'rectangle', x: 0, y: 0, width: 600, height: 400, fill: '#f8f9fa' },
                { id: 2, type: 'rectangle', x: 0, y: 0, width: 600, height: 80, fill: '#343a40' },
                { id: 3, type: 'text', x: 40, y: 50, content: '{{event.name}}', fontSize: 24, fontWeight: 'bold', color: '#ffffff' },
                { id: 4, type: 'text', x: 40, y: 120, content: '{{event.name}}', fontSize: 18, color: '#212529' },
                { id: 5, type: 'text', x: 40, y: 160, content: '{{event.date}} {{event.time}}', fontSize: 14, color: '#6c757d' },
                { id: 6, type: 'line', x1: 40, y1: 200, x2: 560, y2: 200, stroke: '#dee2e6', strokeWidth: 1 },
                { id: 7, type: 'qr', x: 420, y: 230, size: 120 },
                { id: 8, type: 'text', x: 40, y: 250, content: '{{ticket.holder}}', fontSize: 16, color: '#212529' },
                { id: 9, type: 'text', x: 40, y: 280, content: '{{seat.info}}', fontSize: 14, color: '#495057' }
            ]
        }
    }
];

// Переменные для подстановки данных (дефолты — @/lib/ticketBuilder)
const ticketVariables = DEFAULT_TICKET_VARIABLES;

// Панели элементов
const elementCategories = [
    {
        name: 'Текст',
        icon: '📝',
        items: [
            { type: 'text', label: 'Заголовок', defaultData: { content: 'Заголовок', fontSize: 24, fontWeight: 'bold', color: '#000000' } },
            { type: 'text', label: 'Подзаголовок', defaultData: { content: 'Подзаголовок', fontSize: 16, color: '#333333' } },
            { type: 'text', label: 'Основной текст', defaultData: { content: 'Текст', fontSize: 14, color: '#666666' } },
            { type: 'text', label: 'Переменная: Событие', defaultData: { content: '{{event.name}}', fontSize: 16, color: '#000000', isVariable: true } },
            { type: 'text', label: 'Переменная: Дата', defaultData: { content: '{{event.date}}', fontSize: 14, color: '#666666', isVariable: true } },
            { type: 'text', label: 'Переменная: Место', defaultData: { content: '{{seat.info}}', fontSize: 14, color: '#666666', isVariable: true } },
            { type: 'text', label: 'Переменная: Цена', defaultData: { content: '{{ticket.price}} {{ticket.currency}}', fontSize: 18, fontWeight: 'bold', color: '#0f766e', isVariable: true } },
            { type: 'text', label: 'Переменная: Билет №', defaultData: { content: '{{ticket.number}}', fontSize: 14, color: '#666666', isVariable: true } }
        ]
    },
    {
        name: 'Фигуры',
        icon: '⬡',
        items: [
            { type: 'rectangle', label: 'Прямоугольник', defaultData: { width: 100, height: 100, fill: '#3498db', stroke: 'transparent', strokeWidth: 0 } },
            { type: 'circle', label: 'Круг', defaultData: { r: 50, fill: '#e74c3c', stroke: 'transparent', strokeWidth: 0 } },
            { type: 'line', label: 'Линия', defaultData: { x1: 0, y1: 0, x2: 200, y2: 0, stroke: '#000000', strokeWidth: 2 } },
            { type: 'rectangle', label: 'Рамка', defaultData: { width: 100, height: 100, fill: 'transparent', stroke: '#000000', strokeWidth: 2 } }
        ]
    },
    {
        name: 'Медиа',
        icon: '🖼️',
        items: [
            { type: 'qr', label: 'QR Код', defaultData: { size: 100, data: 'https://nabilet.com' } },
            { type: 'image', label: 'Изображение', defaultData: { width: 100, height: 100, src: '' } },
            { type: 'barcode', label: 'Штрих-код', defaultData: { width: 200, height: 50, data: '123456789' } }
        ]
    }
];

// Добавление элемента на холст
function addElement(item) {
    const newElement = normalizeNewElement(item);
    elements.value.push(newElement);
    selectElement(newElement);
}

// Выбор элемента
function selectElement(element) {
    selectedElement.value = element;
}

// Начало перетаскивания
function startDrag(event, element) {
    // В предпросмотре жест не должен ничего двигать: макет только смотрят.
    if (previewMode.value) return;
    isDragging.value = true;
    selectedElement.value = element;
    
    const rect = event.target.getBoundingClientRect();
    dragOffset.value = {
        x: event.clientX - rect.left,
        y: event.clientY - rect.top
    };
}

// Тянучка за угол.
//
// Снапшот нужен, чтобы считать от начала жеста, а не накапливать округления:
// каждый mousemove применяет ПОЛНЫЙ сдвиг от старта к замороженной копии.
// Экранные пиксели делятся на зум — иначе на 50% элемент убегал бы вдвое
// медленнее курсора, а на 200% вдвое быстрее.
const resizing = ref(null);

function startResize(event, element, corner) {
    selectedElement.value = element;
    resizing.value = {
        corner,
        startX: event.clientX,
        startY: event.clientY,
        snapshot: cloneElement(element),
    };
}

function handleResize(event) {
    const state = resizing.value;
    const element = selectedElement.value;
    if (!state || !element) return;

    const scale = zoom.value / 100;
    const dx = (event.clientX - state.startX) / scale;
    const dy = (event.clientY - state.startY) / scale;

    // Откат к снапшоту + полный сдвиг: без дрейфа округлений.
    Object.assign(element, cloneElement(state.snapshot));
    applyCornerResize(element, state.corner, dx, dy);
    clampElementToCanvas(element);
    syncDerivedCoordinates(element);
}

// Удержать элемент в границах холста после ресайза.
function clampElementToCanvas(element) {
    const maxX = Math.max(10, canvasWidth.value);
    const maxY = Math.max(10, canvasHeight.value);

    if (typeof element.x === 'number') {
        element.x = Math.min(Math.max(0, Math.round(element.x)), maxX - 10);
    }
    if (typeof element.y === 'number') {
        element.y = Math.min(Math.max(0, Math.round(element.y)), maxY - 10);
    }
    const isImage = element.type === 'image'
        && typeof element.width === 'number'
        && typeof element.height === 'number'
        && element.height > 0;
    if (isImage) {
        // Картинка — с сохранением пропорций: габарит, упёршийся в край,
        // задаёт второй, а не ломает аспект (как в обычных редакторах).
        const aspect = element.width / element.height;
        const maxW = maxX - element.x;
        const maxH = maxY - element.y;
        let width = Math.min(Math.max(10, Math.round(element.width)), maxW);
        let height = Math.round(width / aspect);
        if (height > maxH) {
            height = Math.max(10, maxH);
            width = Math.round(height * aspect);
        }
        element.width = Math.max(10, width);
        element.height = Math.max(10, height);
        return;
    }
    if (typeof element.width === 'number') {
        element.width = Math.min(Math.max(10, Math.round(element.width)), maxX - element.x);
    }
    if (typeof element.height === 'number') {
        element.height = Math.min(Math.max(10, Math.round(element.height)), maxY - element.y);
    }
    if (typeof element.size === 'number') {
        element.size = Math.min(Math.max(20, Math.round(element.size)), Math.min(maxX, maxY));
        element.width = element.size;
        element.height = element.size;
    }
    if (typeof element.r === 'number') {
        element.r = Math.min(Math.max(5, Math.round(element.r)), Math.min(maxX, maxY));
    }
}

// Слои: порядок в `elements` — снизу вверх (конец массива рисуется верхним).
// Перестановка splice'ом сохраняет объект элемента — выделение не слетает.
function moveLayer(id, direction) {
    moveElementInList(elements.value, id, direction);
}

// Позиция выбранного снизу (1 — самый нижний) — для строки «Слой i из n».
function layerPosition(element) {
    const index = elements.value.findIndex((el) => el.id === element.id);
    return index === -1 ? null : { fromBottom: index + 1, total: elements.value.length };
}

// Слои сверху вниз — для вкладки-списка. computed, а не функция: шаблон
// обращается к списку несколько раз на строку, пересчёт each time ни к чему.
const layersTop = computed(() => [...elements.value].reverse());

// Иконка типа для строки списка слоёв.
function layerIcon(type) {
    switch (type) {
        case 'text': return '📝';
        case 'rectangle': return '▭';
        case 'circle': return '⭕';
        case 'line': return '➖';
        case 'qr': return '🔳';
        case 'image': return '🖼️';
        case 'barcode': return '📊';
        default: return '▫️';
    }
}

// Перемещение элемента
function handleDrag(event) {
    if (!isDragging.value || !selectedElement.value) return;
    
    const canvas = document.getElementById('ticket-canvas');
    const canvasRect = canvas.getBoundingClientRect();
    const scale = zoom.value / 100;
    
    const newX = (event.clientX - canvasRect.left - dragOffset.value.x) / scale;
    const newY = (event.clientY - canvasRect.top - dragOffset.value.y) / scale;
    
    selectedElement.value.x = Math.max(0, Math.min(canvasWidth.value - 50, newX));
    selectedElement.value.y = Math.max(0, Math.min(canvasHeight.value - 50, newY));
    
    syncDerivedCoordinates(selectedElement.value);
}

// Завершение перетаскивания (и тянучки за угол — жест тот же).
function stopDrag() {
    isDragging.value = false;
    resizing.value = null;
}

// Диспетчер движения по холсту: ресайз за угол или обычное перетаскивание.
function onCanvasPointerMove(event) {
    if (resizing.value) {
        handleResize(event);
        return;
    }
    handleDrag(event);
}

// Удаление выбранного элемента
function deleteSelected() {
    if (!selectedElement.value) return;
    const index = elements.value.findIndex(el => el.id === selectedElement.value.id);
    if (index > -1) {
        elements.value.splice(index, 1);
        selectedElement.value = null;
    }
}

// Дублирование элемента (клонирование — в @/lib/ticketBuilder)
function duplicateElement() {
    const duplicate = duplicateAsElement(selectedElement.value);
    if (!duplicate) return;
    elements.value.push(duplicate);
    selectElement(duplicate);
}

// Применение шаблона
// Применить макет к холсту.
//
// Принимает ДВЕ разные формы, и это не небрежность: сохранённый шаблон приходит
// из API как `{ id, name, template_json: { elements } }` (модель кастит
// template_json в массив), а стартовая заготовка — как `{ key, name, config }`.
// Раньше функция знала только про `config`, поэтому сохранённый шаблон из БД
// дал бы `Cannot read properties of undefined (reading 'elements')`.
function elementsOf(source) {
    const config = source?.template_json ?? source?.config ?? {};
    const parsed = typeof config === 'string' ? JSON.parse(config) : config;
    return Array.isArray(parsed?.elements) ? parsed.elements : [];
}

function applyTemplate(template) {
    selectedTemplate.value = template;
    elements.value = cloneElement(elementsOf(template));
    selectedElement.value = null;
    if (template?.name) {
        templateName.value = template.name;
        // Имя из БД — это имя открытого шаблона: подставляем в модалку
        // сохранения, чтобы обновление не создавало второй с тем же именем.
        if (template.id) currentTemplateName.value = template.name;
    }
}

// Фон для миниатюры шаблона в сетке: реальный цвет либо линейный градиент
// из `backgroundGradient`. Раньше превью брало `elements[0].fill` — то есть
// показывало только заливку первого прямоугольника, а для макетов на градиенте
// вообще рисовало белый квадрат. Теперь фон миниатюры совпадает с фоном макета.
function templateBackground(source) {
    const config = source?.template_json ?? source?.config ?? {};
    const parsed = typeof config === 'string' ? JSON.parse(config) : config;
    if (parsed?.backgroundGradient?.from) {
        const g = parsed.backgroundGradient;
        return `linear-gradient(${g.direction || 'to bottom'}, ${g.from}, ${g.to || '#1e293b'})`;
    }
    return parsed?.backgroundColor || '#ffffff';
}

// Применить стартовую заготовку (BUILT_IN_LAYOUTS).
//
// Заготовки нарисованы под холст 600×400, поэтому применение возвращает и
// размер, и фон: иначе макет вылезал бы за вертикальный холст по умолчанию,
// а фон оставался бы от прошлого макета.
function applyLayout(layout) {
    applyTemplate(layout);
    canvasWidth.value = 600;
    canvasHeight.value = 400;
    const bg = layout?.config?.backgroundColor;
    if (typeof bg === 'string' && bg !== '') {
        canvasBackground.value = bg;
    }
}

// Начать новый шаблон с пустого холста.
function resetCanvas() {
    if (elements.value.length && !window.confirm('Очистить холст? Несохранённые изменения будут потеряны.')) {
        return;
    }
    elements.value = [];
    selectedElement.value = null;
    selectedTemplate.value = null;
    templateName.value = '';
    currentTemplateName.value = '';
}

// Сборка `template_json` из состояния редактора.
//
// Раньше `saveTemplate()` жёстко подставлял `backgroundColor: '#ffffff'`,
// затирая реальный фон макета. Теперь фон берётся из `canvasBackground` (либо
// из `canvasGradientConfig`, если включён градиент). `backgroundGradient`
// попадает в payload только когда он задан — при обычном сплошном фоне лишнего
// ключа в `template_json` нет.
function buildCanvasConfig() {
    const config = {
        backgroundColor: canvasBackground.value,
        elements: elements.value,
    };
    const gradient = canvasGradientConfig.value;
    if (gradient) {
        config.backgroundGradient = gradient;
    }
    return config;
}

// Открытие модального окна сохранения
function openSaveModal() {
    // При редактировании существующего шаблона предлагаем его текущее имя:
    // пустое поле на каждое «сохранить» заставляло печатать название заново.
    templateName.value = currentTemplateName.value || '';
    showTemplateModal.value = true;
}

// Сохранение шаблона: создание либо обновление.
//
// Различие принципиально. Раньше всегда шёл POST, поэтому повторное нажатие
// «Сохранить» создавало ВТОРОЙ шаблон с тем же именем, а `templateId`
// оставался прежним — администратор правил один макет, а в списке появлялся
// новый. Теперь при наличии `internalId` идёт PUT.
//
// Отдельно: `internalId`, а не проп. После создания нового шаблона POST'ом
// проп остался бы null, и следующее сохранение снова ушло бы в POST — дубль.
async function saveTemplate() {
    const name = templateName.value.trim();
    if (!name) {
        alert('Введите название шаблона');
        return;
    }

    saving.value = true;
    try {
        const payload = buildTemplatePayload({
            name,
            canvasWidth: canvasWidth.value,
            canvasHeight: canvasHeight.value,
            config: buildCanvasConfig(),
        });

        const isUpdate = internalId.value !== null && internalId.value !== undefined && internalId.value !== '';
        const res = isUpdate
            ? await send(`/ticket-templates/${internalId.value}`, 'PUT', payload)
            : await send('/ticket-templates', 'POST', payload);

        const saved = res.data;
        const node = saved?.data ?? saved;
        if (!isUpdate && node && node.id !== undefined) {
            internalId.value = node.id;
        }
        currentTemplateName.value = name;
        showTemplateModal.value = false;
        emit('template-saved', saved);
        await loadTemplates();
    } catch (e) {
        const detail = e instanceof ApiError && e.details
            ? Object.values(e.details).flat().join(' ')
            : (e instanceof Error ? e.message : String(e));
        alert('Ошибка при сохранении шаблона: ' + detail);
    } finally {
        saving.value = false;
    }
}

// Загрузка существующего шаблона.
//
// Фон и размеры берём из записи: раньше грузились только элементы, и
// сохранённый фон (тёмный у всех заготовок) сбрасывался на белый, а холст —
// на дефолтный. Элементы прогоняем через ensureElementDefaults: старые записи
// могут не знать полей, которые панель и рендер считают обязательными.
async function loadTemplate() {
    if (!props.templateId) return;

    loading.value = true;
    try {
        const res = await get(`/ticket-templates/${props.templateId}`);
        const template = res.data;
        if (!template) return;

        // Модель кастит template_json в массив; строка — легаси двойного кодирования.
        const config = typeof template.template_json === 'string'
            ? JSON.parse(template.template_json)
            : (template.template_json ?? {});

        canvasWidth.value = template.width || 600;
        canvasHeight.value = template.height || 400;
        canvasBackground.value = typeof config.backgroundColor === 'string' && config.backgroundColor !== ''
            ? config.backgroundColor
            : '#ffffff';
        elements.value = (config.elements || []).map((el) => ensureElementDefaults(cloneElement(el)));
        internalId.value = template.id ?? props.templateId;
        currentTemplateName.value = template.name || '';

        // Восстановить фон холста: сплошной цвет либо линейный градиент.
        // Раньше фон при загрузке сбрасывался на белый, поэтому сохранённый
        // тёмный/градиентный макет открывался на белом фоне с «потерянными»
        // элементами-подложками.
        const bg = config.backgroundColor || '#ffffff';
        canvasBackground.value = bg;
        if (config.backgroundGradient?.from) {
            useGradient.value = true;
            gradientFrom.value = config.backgroundGradient.from;
            gradientTo.value = config.backgroundGradient.to || '#1e293b';
            gradientDir.value = config.backgroundGradient.direction || 'to bottom';
        } else {
            useGradient.value = false;
            gradientFrom.value = '#0f766e';
            gradientTo.value = '#1e293b';
            gradientDir.value = 'to bottom';
        }
    } catch (e) {
        loadError.value = e instanceof Error ? e.message : String(e);
    } finally {
        loading.value = false;
    }
}

// Удаление текущего шаблона.
//
// Подтверждение обязательно: макет собирается вручную и нигде не дублируется,
// поэтому удаление необратимо.
async function deleteTemplate() {
    if (internalId.value === null || internalId.value === undefined || internalId.value === '') return;
    if (!window.confirm('Удалить шаблон? Действие необратимо.')) return;

    deleting.value = true;
    try {
        await send(`/ticket-templates/${internalId.value}`, 'DELETE');
        emit('template-deleted', internalId.value);
    } catch (e) {
        alert('Не удалось удалить шаблон: ' + (e instanceof Error ? e.message : String(e)));
    } finally {
        deleting.value = false;
    }
}

// Текст элемента на холсте: с тестовыми данными или сырым плейсхолдером.
//
// Выключатель нужен не для красоты: администратор должен видеть, где в макете
// переменная, а где статичный текст, — иначе опечатка в {{...}} выглядит как
// готовое значение и вскрывается только в письме покупателю.
function displayContent(element) {
    if (!showSampleData.value) return element.content || '';
    return renderVars(element.content, ticketVariables);
}

// Пикер изображений из медиатеки.
//
// Картинка выбирается глазами, поэтому список — сеткой превью, а не select.
// Загрузка идёт лениво, при первом открытии: конструктор не должен ждать
// медиатеку, если администратор правит только текст.
async function openMediaPicker() {
    mediaPickerOpen.value = true;
    if (mediaItems.value.length > 0) return;

    mediaLoading.value = true;
    mediaError.value = null;
    try {
        const res = await get('/media?per_page=48');
        const payload = res.data;
        mediaItems.value = Array.isArray(payload) ? payload : (payload?.data ?? []);
    } catch (e) {
        mediaError.value = e instanceof Error ? e.message : String(e);
        mediaItems.value = [];
    } finally {
        mediaLoading.value = false;
    }
}

// Поставить картинку в выбранный элемент.
//
// Размер подгоняем под исходник (вписанный в холст): афиша обычно и должна
// занимать билет по ширине, а тянуть углы вручную после каждой вставки —
// лишний шаг. Вручную размер правится ниже, в секции «Размер».
function pickMedia(asset) {
    if (!selectedElement.value) return;
    selectedElement.value.src = asset.url;

    const naturalWidth = Number(asset.width);
    const naturalHeight = Number(asset.height);
    if (Number.isFinite(naturalWidth) && naturalWidth > 0 && Number.isFinite(naturalHeight) && naturalHeight > 0) {
        const width = Math.min(naturalWidth, canvasWidth.value);
        selectedElement.value.width = Math.round(width);
        selectedElement.value.height = Math.round((width * naturalHeight) / naturalWidth);
    }

    mediaPickerOpen.value = false;
}

// Отрисовка переменной текста (подстановка {{var}} — в @/lib/ticketBuilder)
function renderTextContent(content) {
    return renderVars(content, ticketVariables);
}

// Локальный рендер QR для превью на холсте.
//
// Шаблон не может вызвать асинхронную функцию: `renderQrDataUrl` возвращает
// Promise, а `:src` ждёт строку. Поэтому коды считаются заранее по watcher'у и
// складываются в кэш, а шаблон только читает готовый data-URI.
//
// Ключ кэша — `id:payload`: при перетаскивании элемента payload не меняется,
// и код не перерисовывается; при смене данных (например, при подстановке
// реального билета вместо заглушки) ключ меняется и код строится заново.
const qrDataUrls = ref({});

watch(
    () => elements.value
        .filter((el) => el.type === 'qr')
        .map((el) => [el.id, String(el.data || QR_PREVIEW_FALLBACK)]),
    async (qrElements) => {
        for (const [id, payload] of qrElements) {
            const key = `${id}:${payload}`;
            if (qrDataUrls.value[key] !== undefined) continue;

            try {
                qrDataUrls.value[key] = await renderQrDataUrl(payload, { width: 240, margin: 1 });
            } catch {
                // Пустая строка, а не исключение: один нечитаемый элемент не
                // должен ронять весь конструктор. Шаблон покажет «QR недоступен».
                qrDataUrls.value[key] = '';
            }
        }
    },
    { immediate: true, deep: true },
);

/** Готовый data-URI для элемента-QR, либо '' пока код не построен. */
function qrPreviewSrc(element) {
    const payload = String(element.data || QR_PREVIEW_FALLBACK);
    return qrDataUrls.value[`${element.id}:${payload}`] || '';
}

// Печать макета (она же «сохранить как PDF»).
//
// Раньше здесь был POST на `/api/tickets/generate-preview` — эндпоинта не
// существует, PDF-пакета в composer.json нет, поэтому кнопка всегда падала в
// `alert('Ошибка при генерации PDF')`. Вместо фиктивного сервера используем
// печать браузера: диалог печати умеет «Сохранить как PDF» из коробки, а
// `@media print` в конце файла оставляет на листе только холст билета.
function printPreview() {
    window.print();
}

// Изменение размера холста (пресеты — CANVAS_PRESETS из @/lib/ticketBuilder)
function resizeCanvas(preset) {
    const size = CANVAS_PRESETS[preset];
    if (!size) return;
    canvasWidth.value = size.width;
    canvasHeight.value = size.height;
}

onMounted(() => {
    // Шаблоны нужны всегда (вкладка «Шаблоны»), поэтому грузим сразу.
    loadTemplates();
    if (props.templateId) {
        loadTemplate();
    }
});
</script>

<template>
    <div class="ticket-builder-container" :class="{ 'builder-light': !ui.isDark }">
        <!-- Toolbar -->
        <div class="builder-toolbar">
            <div class="toolbar-section">
                <button @click="openSaveModal" :disabled="saving" class="btn btn-primary">
                    💾 {{ saving ? 'Сохранение…' : (internalId ? 'Обновить шаблон' : 'Сохранить шаблон') }}
                </button>
                <button @click="printPreview" class="btn btn-success">
                    🖨️ Печать / PDF
                </button>
                <button @click="deleteSelected" :disabled="!selectedElement" class="btn btn-danger">
                    🗑️ Удалить
                </button>
                <button @click="duplicateElement" :disabled="!selectedElement" class="btn btn-secondary">
                    📋 Дублировать
                </button>
                <button @click="previewMode = !previewMode" class="btn btn-secondary">
                    {{ previewMode ? '✏️ Редактировать' : '👁 Предпросмотр' }}
                </button>
            </div>
            
            <div class="toolbar-section">
                <label class="toolbar-label">Размер:</label>
                <select :value="presetKey" @change="resizeCanvas($event.target.value)" class="toolbar-select">
                    <option value="mobile">Мобильный (400×600)</option>
                    <option value="story">Сторис (450×800)</option>
                    <option value="desktop">Десктоп (600×400)</option>
                    <option value="square">Квадрат (500×500)</option>
                    <option value="wide">Широкий (800×400)</option>
                    <option value="" disabled>Свой размер</option>
                </select>
            </div>
            
            <div class="toolbar-section">
                <button @click="zoom = Math.max(zoom - 10, 50)" class="btn btn-icon">−</button>
                <span class="zoom-value">{{ zoom }}%</span>
                <button @click="zoom = Math.min(zoom + 10, 200)" class="btn btn-icon">+</button>
            </div>
        </div>

        <!-- Строка состояния: какой шаблон открыт и не сорвалась ли загрузка.
             Раньше ошибка загрузки уходила только в console.error, поэтому
             открытый шаблон молча оставался пустым холстом, и администратор
             начинал рисовать поверх несуществующего макета. -->
        <div v-if="loading || loadError || currentTemplateName || !internalId" class="builder-status">
            <span v-if="loading">Загружаем шаблон…</span>
            <span v-else-if="loadError" class="builder-status-error">
                Не удалось загрузить шаблон: {{ loadError }}
            </span>
            <span v-else-if="!internalId && !currentTemplateName">
                Новый шаблон — выберите заготовку на вкладке «Шаблоны» или соберите макет из элементов.
            </span>
            <span v-else>
                Открыт шаблон: <b>{{ currentTemplateName }}</b>
                <template v-if="internalId"> · обновление перезапишет его</template>
            </span>
        </div>

        <!-- Полоса предпросмотра: выход и тестовые данные. -->
        <div v-if="previewMode" class="preview-bar">
            <span class="preview-bar-title">👁 Предпросмотр — так билет увидит покупатель</span>
            <label class="preview-bar-toggle">
                <input type="checkbox" v-model="showSampleData" />
                Тестовые данные
            </label>
            <button @click="previewMode = false" class="btn btn-secondary">✏️ К редактору</button>
        </div>

        <div class="builder-main" :class="{ 'is-preview': previewMode }">
            <!-- Left Sidebar - Elements -->
            <div v-if="!previewMode" class="sidebar sidebar-left">
                <div class="sidebar-tabs">
                    <button 
                        :class="{ active: activeTab === 'elements' }" 
                        @click="activeTab = 'elements'"
                        class="tab-btn"
                    >
                        📦 Элементы
                    </button>
                    <button 
                        :class="{ active: activeTab === 'templates' }" 
                        @click="activeTab = 'templates'"
                        class="tab-btn"
                    >
                        🎨 Шаблоны
                    </button>
                    <button
                        :class="{ active: activeTab === 'layers' }"
                        @click="activeTab = 'layers'"
                        class="tab-btn"
                    >
                        🗂️ Слои
                    </button>
                </div>
                
                <!-- Elements Panel -->
                <div v-if="activeTab === 'elements'" class="panel-content">
                    <div v-for="category in elementCategories" :key="category.name" class="element-category">
                        <h4 class="category-title">
                            <span>{{ category.icon }}</span> {{ category.name }}
                        </h4>
                        <div class="element-grid">
                            <div 
                                v-for="item in category.items" 
                                :key="item.label"
                                @click="addElement(item)"
                                class="element-item"
                            >
                                {{ item.label }}
                            </div>
                        </div>
                    </div>
                    
                    <!-- `v-pre`: ниже — документация синтаксиса {{...}}, а не
                      выражения Vue. Без него рендер падает с
                      `Cannot read properties of undefined (reading 'name')`,
                      и весь конструктор остаётся пустым — именно так впервые
                      и вскрылось: страница раньше никогда его не монтировала. -->
                    <div class="variables-info" v-pre>
                        <h4>📋 Доступные переменные:</h4>
                        <ul>
                            <li><code>{{event.name}}</code> - Название события</li>
                            <li><code>{{event.date}}</code> - Дата</li>
                            <li><code>{{event.time}}</code> - Время</li>
                            <li><code>{{event.venue}}</code> - Место проведения</li>
                            <li><code>{{ticket.number}}</code> - Номер билета</li>
                            <li><code>{{ticket.holder}}</code> - Владелец</li>
                            <li><code>{{ticket.price}}</code> - Цена (без символа валюты)</li>
                            <li><code>{{ticket.currency}}</code> - Символ валюты</li>
                            <li><code>{{seat.row}}</code> - Ряд</li>
                            <li><code>{{seat.number}}</code> - Место</li>
                            <li><code>{{seat.info}}</code> - Информация о месте</li>
                            <li><code>{{order.id}}</code> - Номер заказа</li>
                        </ul>
                    </div>
                </div>
                
                <!-- Templates Panel -->
                <div v-if="activeTab === 'templates'" class="panel-content">
                    <div class="templates-actions">
                        <button @click="resetCanvas" class="btn btn-secondary btn-block">＋ Новый пустой</button>
                        <button
                            v-if="internalId"
                            @click="deleteTemplate"
                            :disabled="deleting"
                            class="btn btn-danger btn-block"
                        >
                            🗑️ {{ deleting ? 'Удаление…' : 'Удалить шаблон' }}
                        </button>
                    </div>

                    <h4 class="category-title">Мои шаблоны</h4>

                    <p v-if="templatesLoading" class="panel-hint">Загружаем шаблоны…</p>
                    <p v-else-if="templatesError" class="panel-hint panel-hint-error">
                        Не удалось загрузить: {{ templatesError }}
                    </p>
                    <p v-else-if="!templates.length" class="panel-hint">
                        Сохранённых шаблонов пока нет. Соберите макет и нажмите «Сохранить шаблон».
                    </p>

                    <div v-else class="templates-grid">
                        <div
                            v-for="template in templates"
                            :key="template.id"
                            @click="applyTemplate(template)"
                            class="template-card"
                            :class="{ selected: selectedTemplate?.id === template.id }"
                        >
                            <div class="template-preview" :style="{ background: templateBackground(template) }">
                                <div v-for="el in elementsOf(template).slice(0, 8)" :key="el.id" class="preview-element">
                                    <span v-if="el.type === 'text'" :style="{
                                        fontSize: `${(el.fontSize || 14) / 3}px`,
                                        color: el.color,
                                        fontWeight: el.fontWeight
                                    }">{{ String(el.content || '').substring(0, 15) }}</span>
                                    <div v-if="el.type === 'qr'" class="preview-qr"></div>
                                    <div v-if="el.type === 'rectangle'" class="preview-shape" :style="{ background: el.fill }"></div>
                                </div>
                            </div>
                            <p class="template-name">{{ template.name }}</p>
                            <p class="template-meta">{{ template.width }}×{{ template.height }}</p>
                        </div>
                    </div>

                    <!-- Стартовые заготовки: не шаблоны из БД, а макеты-образцы,
                         из которых начинают новый билет. -->
                    <h4 class="category-title">Заготовки</h4>
                    <div class="templates-grid">
                        <div
                            v-for="layout in BUILT_IN_LAYOUTS"
                            :key="layout.key"
                            @click="applyLayout(layout)"
                            class="template-card"
                        >
                            <div class="template-preview" :style="{ background: templateBackground(layout) }">
                                <div v-for="el in layout.config.elements" :key="el.id" class="preview-element">
                                    <span v-if="el.type === 'text'" :style="{
                                        fontSize: `${(el.fontSize || 14) / 3}px`,
                                        color: el.color,
                                        fontWeight: el.fontWeight
                                    }">{{ String(el.content || '').substring(0, 15) }}</span>
                                    <div v-if="el.type === 'qr'" class="preview-qr"></div>
                                    <div v-if="el.type === 'rectangle'" class="preview-shape" :style="{ background: el.fill }"></div>
                                </div>
                            </div>
                            <p class="template-name">{{ layout.name }}</p>
                        </div>
                    </div>
                </div>

                <!-- Layers Panel: порядок сверху вниз — как лежит на билете.
                     Верх списка = верхний слой (рисуется последним). -->
                <div v-if="activeTab === 'layers'" class="panel-content">
                    <p class="panel-hint" style="margin-bottom: 10px">
                        {{ elements.length === 0 ? 'Элементов пока нет.' : `Слоёв: ${elements.length}. Верх списка — верхний слой.` }}
                    </p>
                    <div v-if="elements.length" class="layers-list">
                        <div
                            v-for="layer in layersTop"
                            :key="layer.id"
                            class="layer-row"
                            :class="{ selected: selectedElement && selectedElement.id === layer.id }"
                            @click="selectElement(layer)"
                        >
                            <span class="layer-icon">{{ layerIcon(layer.type) }}</span>
                            <span class="layer-label">{{ elementLayerLabel(layer) }}</span>
                            <span class="layer-actions">
                                <button
                                    type="button"
                                    class="layer-btn"
                                    title="Слой выше"
                                    :disabled="layersTop.length === 0 || layer.id === layersTop[0].id"
                                    @click.stop="moveLayer(layer.id, 'up')"
                                >↑</button>
                                <button
                                    type="button"
                                    class="layer-btn"
                                    title="Слой ниже"
                                    :disabled="layersTop.length === 0 || layer.id === layersTop[layersTop.length - 1].id"
                                    @click.stop="moveLayer(layer.id, 'down')"
                                >↓</button>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Canvas -->
            <div class="canvas-area">
                <div 
                    id="ticket-canvas"
                    class="ticket-canvas"
                    :style="{ 
                        width: `${canvasWidth * zoom / 100}px`,
                        height: `${canvasHeight * zoom / 100}px`
                    }"
                    @mousemove="onCanvasPointerMove"
                    @mouseup="stopDrag"
                    @mouseleave="stopDrag"
                >
                    <div
                        class="canvas-inner"
                        :class="{ 'is-preview': previewMode }"
                        :style="{
                            width: `${canvasWidth}px`,
                            height: `${canvasHeight}px`,
                            transform: `scale(${zoom / 100})`,
                            transformOrigin: 'top left',
                            background: canvasBackgroundStyle
                        }"
                    >
                        <!-- Rendered Elements -->
                        <div 
                            v-for="element in elements" 
                            :key="element.id"
                            class="canvas-element"
                            :class="{ selected: !!selectedElement && selectedElement.id === element.id }"
                            :style="{
                                left: `${element.x}px`,
                                top: `${element.y}px`,
                                transform: `rotate(${element.rotation}deg)`,
                                opacity: element.opacity
                            }"
                            @mousedown.stop="startDrag($event, element)"
                            @click.stop="selectElement(element)"
                        >
                            <!-- Text Element -->
                            <div 
                                v-if="element.type === 'text'"
                                class="element-text"
                                :style="{
                                    fontSize: `${element.fontSize}px`,
                                    fontWeight: element.fontWeight,
                                    fontFamily: element.fontFamily || undefined,
                                    color: element.color,
                                    // Ширина из макета — та же, что уходит в PDF.
                                    // Пока холст игнорировал `width` и рисовал
                                    // `white-space: nowrap`, длинное название
                                    // события вылезало за билет в редакторе и
                                    // переносилось в письме — то есть WYSIWYG
                                    // врал ровно там, где это заметно.
                                    width: element.width ? `${element.width}px` : 'auto',
                                    whiteSpace: element.width ? 'pre-wrap' : 'nowrap',
                                    wordBreak: 'break-word'
                                }"
                            >
                                {{ displayContent(element) }}
                            </div>
                            
                            <!-- Rectangle Element -->
                            <div 
                                v-if="element.type === 'rectangle'"
                                class="element-rectangle"
                                :style="{
                                    width: `${element.width}px`,
                                    height: `${element.height}px`,
                                    backgroundColor: element.fill,
                                    border: element.strokeWidth > 0 ? `${element.strokeWidth}px solid ${element.stroke}` : 'none',
                                    // `borderRadius` в модели — число, а CSS ждёт
                                    // единицу: без «px» браузер отбрасывал
                                    // `border-radius: 12` как некорректное, и
                                    // скругления не было ни в редакторе, ни в
                                    // панели свойств.
                                    borderRadius: element.borderRadius ? `${element.borderRadius}px` : '0px'
                                }"
                            ></div>
                            
                            <!-- Circle Element -->
                            <div 
                                v-if="element.type === 'circle'"
                                class="element-circle"
                                :style="{
                                    width: `${element.r * 2}px`,
                                    height: `${element.r * 2}px`,
                                    backgroundColor: element.fill,
                                    border: element.strokeWidth > 0 ? `${element.strokeWidth}px solid ${element.stroke}` : 'none',
                                    borderRadius: '50%'
                                }"
                            ></div>
                            
                            <!-- Line Element -->
                            <div 
                                v-if="element.type === 'line'"
                                class="element-line"
                                :style="{
                                    width: `${Math.sqrt(Math.pow(element.x2 - element.x, 2) + Math.pow(element.y2 - element.y, 2))}px`,
                                    height: `${element.strokeWidth}px`,
                                    backgroundColor: element.stroke,
                                    transform: `rotate(${Math.atan2(element.y2 - element.y, element.x2 - element.x)}rad)`
                                }"
                            ></div>
                            
                            <!-- QR Code Element.
                                 `src` — локально построенный data-URI
                                 (@/lib/qr). Пока код не готов, показываем
                                 рамку-заглушку, а не пустой <img>: пустая
                                 картинка выглядит как сломанный билет. -->
                            <div 
                                v-if="element.type === 'qr'"
                                class="element-qr"
                                :style="{ width: `${element.size}px`, height: `${element.size}px` }"
                            >
                                <img
                                    v-if="qrPreviewSrc(element)"
                                    :src="qrPreviewSrc(element)"
                                    alt="QR Code"
                                />
                                <div v-else class="qr-placeholder">QR</div>
                            </div>
                            
                            <!-- Barcode Element -->
                            <div 
                                v-if="element.type === 'barcode'"
                                class="element-barcode"
                                :style="{ width: `${element.width}px`, height: `${element.height}px` }"
                            >
                                <div class="barcode-placeholder">
                                    BARCODE
                                </div>
                            </div>
                            
                            <!-- Image Element: афиша или картинка из медиатеки.
                                 Без src — рамка-заглушка, а не битый <img>. -->
                            <div 
                                v-if="element.type === 'image'"
                                class="element-image"
                                :style="{ width: `${element.width}px`, height: `${element.height}px` }"
                            >
                                <img
                                    v-if="element.src"
                                    :src="storageSrc(element.src)"
                                    alt=""
                                    class="element-image-img"
                                />
                                <div v-else class="image-placeholder">
                                    IMG
                                </div>
                            </div>

                            <!-- Ручки ресайза у выбранного элемента. `.stop` —
                                 чтобы тянучка не начинала перетаскивание самого
                                 элемента; движение ловит холст (см.
                                 onCanvasPointerMove). Подпись — какая сторона
                                 тянется: у кругов/QR/текста/линий ручка одна. -->
                            <span
                                v-for="corner in (selectedElement && selectedElement.id === element.id ? resizeHandlesFor(element.type) : [])"
                                :key="corner"
                                class="resize-handle"
                                :class="'resize-' + corner"
                                title="Потяните, чтобы изменить размер"
                                @mousedown.stop="startResize($event, element, corner)"
                            />
                        </div>
                        
                        <!-- Grid overlay for alignment -->
                        <div class="grid-overlay"></div>
                    </div>
                </div>
            </div>
            
            <!-- Right Sidebar - Properties -->
            <div v-if="!previewMode && selectedElement" class="sidebar sidebar-right">
                <h3 class="properties-title">Свойства</h3>

                <!-- Фон холста: сплошной цвет и/или линейный градиент («текстура»).
                     Панель видна всегда — фон редактируется независимо от выбранного
                     элемента, иначе поменять тёмный фон можно было бы только по
                     случайному попаданию ни на один элемент. -->
                <div class="property-group">
                    <h4>Фон холста</h4>

                    <label class="property-label">Цвет фона:</label>
                    <input
                        type="color"
                        v-model="canvasBackground"
                        :disabled="useGradient"
                        class="property-color"
                    />

                    <label class="property-label">Градиент (текстура):</label>
                    <label class="property-inline">
                        <input type="checkbox" v-model="useGradient" /> Использовать градиент
                    </label>

                    <template v-if="useGradient">
                        <label class="property-label">От:</label>
                        <input type="color" v-model="gradientFrom" class="property-color" />

                        <label class="property-label">До:</label>
                        <input type="color" v-model="gradientTo" class="property-color" />

                        <label class="property-label">Направление:</label>
                        <select v-model="gradientDir" class="property-select">
                            <option value="to bottom">Сверху вниз</option>
                            <option value="to right">Слева направо</option>
                            <option value="to top right">По диагонали ↗</option>
                            <option value="135deg">135°</option>
                            <option value="45deg">45°</option>
                        </select>
                    </template>
                </div>
                
                <!-- Свойства элемента — только когда выбран конкретный элемент. -->
                <template v-if="selectedElement">
                <!-- Text Properties -->
                <div v-if="selectedElement.type === 'text'" class="property-group">
                    <label class="property-label">Текст:</label>
                    <textarea 
                        v-model="selectedElement.content" 
                        class="property-input property-textarea"
                        rows="3"
                    ></textarea>
                    
                    <label class="property-label">Размер шрифта:</label>
                    <input 
                        type="range" 
                        v-model.number="selectedElement.fontSize" 
                        min="8" 
                        max="72"
                        class="property-range"
                    />
                    <span class="property-value">{{ selectedElement.fontSize }}px</span>

                    <label class="property-label">Шрифт:</label>
                    <select v-model="selectedElement.fontFamily" class="property-select">
                        <option value="">По умолчанию</option>
                        <option v-for="font in FONT_STACKS" :key="font.key" :value="font.css">
                            {{ font.label }}
                        </option>
                    </select>
                    
                    <label class="property-label">Цвет:</label>
                    <input type="color" v-model="selectedElement.color" class="property-color" />
                    
                    <label class="property-label">Жирный:</label>
                    <select v-model="selectedElement.fontWeight" class="property-select">
                        <option value="normal">Обычный</option>
                        <option value="bold">Жирный</option>
                    </select>
                </div>

                <!-- Image Properties: афиша из медиатеки или произвольный URL. -->
                <div v-if="selectedElement.type === 'image'" class="property-group">
                    <label class="property-label">Изображение:</label>
                    <button @click="openMediaPicker" class="btn btn-secondary btn-block">
                        🖼️ Выбрать из медиатеки
                    </button>
                    <input
                        v-model="selectedElement.src"
                        type="text"
                        placeholder="…или вставьте URL картинки"
                        class="property-input property-src"
                    />
                    <img
                        v-if="selectedElement.src"
                        :src="storageSrc(selectedElement.src)"
                        alt=""
                        class="property-thumb"
                    />
                </div>

                <!-- QR Properties -->
                <div v-if="selectedElement.type === 'qr'" class="property-group">
                    <label class="property-label">Размер кода:</label>
                    <input
                        type="range"
                        v-model.number="selectedElement.size"
                        min="40"
                        max="400"
                        class="property-range"
                    />
                    <span class="property-value">{{ selectedElement.size }}px</span>
                </div>
                
                <!-- Shape Properties -->
                <div v-if="['rectangle', 'circle'].includes(selectedElement.type)" class="property-group">
                    <label class="property-label">Заливка:</label>
                    <input type="color" v-model="selectedElement.fill" class="property-color" />
                    
                    <label class="property-label">Обводка:</label>
                    <input type="color" v-model="selectedElement.stroke" class="property-color" />
                    
                    <label class="property-label">Толщина обводки:</label>
                    <input 
                        type="range" 
                        v-model.number="selectedElement.strokeWidth" 
                        min="0" 
                        max="20"
                        class="property-range"
                    />
                    <span class="property-value">{{ selectedElement.strokeWidth }}px</span>
                    
                    <label v-if="selectedElement.type === 'rectangle'" class="property-label">Скругление:</label>
                    <input 
                        v-if="selectedElement.type === 'rectangle'"
                        type="range" 
                        v-model.number="selectedElement.borderRadius" 
                        min="0" 
                        max="50"
                        class="property-range"
                    />
                </div>

                <!-- Size Properties: ширина/высота — то, чего не хватало всем
                     типам, кроме текста (у него размер — это кегль выше). -->
                <div v-if="['rectangle', 'image', 'barcode'].includes(selectedElement.type)" class="property-group">
                    <h4>Размер</h4>
                    <div class="position-row">
                        <label>Ш:</label>
                        <input
                            type="number"
                            v-model.number="selectedElement.width"
                            min="10"
                            class="property-input-small"
                        />
                        <label>В:</label>
                        <input
                            type="number"
                            v-model.number="selectedElement.height"
                            min="10"
                            class="property-input-small"
                        />
                    </div>
                </div>

                <div v-if="selectedElement.type === 'circle'" class="property-group">
                    <h4>Размер</h4>
                    <label class="property-label">Радиус:</label>
                    <input
                        type="range"
                        v-model.number="selectedElement.r"
                        min="5"
                        max="300"
                        class="property-range"
                    />
                    <span class="property-value">{{ selectedElement.r }}px</span>
                </div>

                <div v-if="selectedElement.type === 'line'" class="property-group">
                    <h4>Размер</h4>
                    <label class="property-label">Длина (по горизонтали):</label>
                    <input
                        type="number"
                        :value="Math.round((selectedElement.x2 ?? selectedElement.x) - selectedElement.x)"
                        @input="selectedElement.x2 = selectedElement.x + Math.max(10, Number($event.target.value) || 10); selectedElement.y2 = selectedElement.y"
                        min="10"
                        class="property-input"
                    />
                    <label class="property-label">Толщина:</label>
                    <input
                        type="range"
                        v-model.number="selectedElement.strokeWidth"
                        min="1"
                        max="20"
                        class="property-range"
                    />
                    <span class="property-value">{{ selectedElement.strokeWidth }}px</span>
                </div>
                
                <!-- Layer Order: кто выше/ниже. Тот же порядок, что во вкладке
                     «Слои»: конец списка elements рисуется верхним. -->
                <div class="property-group">
                    <h4>Слой{{ layerPosition(selectedElement) ? ` · ${layerPosition(selectedElement).fromBottom} из ${layerPosition(selectedElement).total} (снизу)` : '' }}</h4>
                    <div class="layer-buttons">
                        <button type="button" class="btn btn-secondary btn-block" @click="moveLayer(selectedElement.id, 'top')">⤴ Наверх</button>
                        <button type="button" class="btn btn-secondary btn-block" @click="moveLayer(selectedElement.id, 'up')">↑ Выше</button>
                        <button type="button" class="btn btn-secondary btn-block" @click="moveLayer(selectedElement.id, 'down')">↓ Ниже</button>
                        <button type="button" class="btn btn-secondary btn-block" @click="moveLayer(selectedElement.id, 'bottom')">⤵ Вниз</button>
                    </div>
                </div>

                <!-- Common Properties -->
                <div class="property-group">
                    <label class="property-label">Прозрачность:</label>
                    <input 
                        type="range" 
                        v-model.number="selectedElement.opacity" 
                        min="0" 
                        max="1" 
                        step="0.1"
                        class="property-range"
                    />
                    <span class="property-value">{{ Math.round((selectedElement.opacity ?? 1) * 100) }}%</span>
                    
                    <label class="property-label">Вращение:</label>
                    <input 
                        type="range" 
                        v-model.number="selectedElement.rotation" 
                        min="0" 
                        max="360"
                        class="property-range"
                    />
                    <span class="property-value">{{ selectedElement.rotation ?? 0 }}°</span>
                </div>
                
                <!-- Position Properties -->
                <div class="property-group">
                    <h4>Позиция</h4>
                    <div class="position-row">
                        <label>X:</label>
                        <input 
                            type="number" 
                            v-model.number="selectedElement.x" 
                            class="property-input-small"
                        />
                        <label>Y:</label>
                        <input 
                            type="number" 
                            v-model.number="selectedElement.y" 
                            class="property-input-small"
                        />
                    </div>
                </div>
                </template>
            </div>

            <!-- Canvas Properties: когда ничего не выбрано — настройки холста,
                 а не пустая панель. Фон здесь же: раньше его было негде поменять. -->
            <div v-else-if="!previewMode" class="sidebar sidebar-right">
                <h3 class="properties-title">Холст</h3>
                <div class="property-group">
                    <label class="property-label">Фон холста:</label>
                    <input type="color" v-model="canvasBackground" class="property-color" />
                    <span class="property-value">{{ canvasWidth }}×{{ canvasHeight }} · кликните элемент, чтобы править его</span>
                </div>
            </div>
        </div>
        
        <!-- Save Modal -->
        <div v-if="showTemplateModal" class="modal-overlay" @click.self="showTemplateModal = false">
            <div class="modal">
                <h3>Сохранение шаблона билета</h3>
                <input 
                    v-model="templateName" 
                    type="text" 
                    placeholder="Название шаблона" 
                    class="modal-input"
                    autofocus
                />
                <div class="modal-actions">
                    <button @click="showTemplateModal = false" class="btn btn-secondary">
                        Отмена
                    </button>
                    <button @click="saveTemplate" class="btn btn-primary">
                        Сохранить
                    </button>
                </div>
            </div>
        </div>

        <!-- Media Picker: выбор картинки из медиатеки для элемента-изображения. -->
        <div v-if="mediaPickerOpen" class="modal-overlay" @click.self="mediaPickerOpen = false">
            <div class="modal modal-wide">
                <h3>🖼️ Изображение из медиатеки</h3>
                <p v-if="mediaLoading" class="panel-hint">Загружаем файлы…</p>
                <p v-else-if="mediaError" class="panel-hint panel-hint-error">
                    Не удалось загрузить: {{ mediaError }}
                </p>
                <div v-else-if="mediaImages.length" class="media-grid">
                    <button
                        v-for="asset in mediaImages"
                        :key="asset.id"
                        type="button"
                        class="media-card"
                        @click="pickMedia(asset)"
                    >
                        <img :src="storageSrc(asset.url)" :alt="asset.title || asset.filename" loading="lazy" />
                        <span>{{ asset.title || asset.filename }}</span>
                    </button>
                </div>
                <p v-else class="panel-hint">
                    Картинок в медиатеке нет — загрузите их в разделе «Файлы», затем вернитесь сюда.
                </p>
                <div class="modal-actions">
                    <button @click="mediaPickerOpen = false" class="btn btn-secondary">
                        Отмена
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* БАЗОВАЯ ТЕМА — ТЁМНАЯ: конструктор живёт внутри тёмной админки.
   Светлая включается классом `.builder-light` на контейнере (см. низ файла),
   когда стор темы говорит `light`. */
.ticket-builder-container {
    display: flex;
    flex-direction: column;
    height: 100vh;
    background: #0b0e1f;
    color: #e6e9f5;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

/* Toolbar */
.builder-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 20px;
    background: #141832;
    color: white;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
    border-bottom: 1px solid #262b4d;
    flex-wrap: wrap;
    gap: 10px;
}

.toolbar-section {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.toolbar-label {
    font-size: 13px;
    color: #9aa3c7;
}

.toolbar-select {
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid #2b3160;
    background: #0f1330;
    color: #e6e9f5;
    font-size: 13px;
}

.zoom-value {
    min-width: 50px;
    text-align: center;
    font-weight: 600;
}

/* Buttons */
.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover:not(:disabled) {
    background: #2563eb;
}

.btn-success {
    background: #10b981;
    color: white;
}

.btn-success:hover:not(:disabled) {
    background: #059669;
}

.btn-danger {
    background: #ef4444;
    color: white;
}

.btn-danger:hover:not(:disabled) {
    background: #dc2626;
}

.btn-secondary {
    background: #64748b;
    color: white;
}

.btn-secondary:hover:not(:disabled) {
    background: #475569;
}

.btn-icon {
    padding: 8px 12px;
    background: #475569;
    color: white;
    min-width: 40px;
}

.btn-icon:hover:not(:disabled) {
    background: #64748b;
}

/* Полоса предпросмотра */
.preview-bar {
    flex: none;
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 8px 20px;
    background: #141832;
    border-bottom: 1px solid #262b4d;
    font-size: 13px;
}

.preview-bar-title {
    color: #e6e9f5;
    font-weight: 600;
}

.preview-bar-toggle {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #aab3d8;
    cursor: pointer;
}

.builder-main.is-preview .canvas-area {
    padding: 32px;
}

/* Main Area */
.builder-main {
    display: flex;
    flex: 1;
    overflow: hidden;
}

/* Sidebars */
.sidebar {
    width: 280px;
    background: #141832;
    border-right: 1px solid #262b4d;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    flex: none;
}

.sidebar-right {
    border-right: none;
    border-left: 1px solid #262b4d;
}

.sidebar-tabs {
    display: flex;
    border-bottom: 1px solid #262b4d;
}

.tab-btn {
    flex: 1;
    padding: 12px;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.2s;
    border-bottom: 2px solid transparent;
    color: #9aa3c7;
}

.tab-btn:hover {
    background: #1d2350;
}

.tab-btn.active {
    background: #1a2456;
    border-bottom-color: #7ea4ff;
    color: #7ea4ff;
    font-weight: 600;
}

.panel-content {
    padding: 16px;
}

/* Element Categories */
.element-category {
    margin-bottom: 20px;
}

.category-title {
    font-size: 14px;
    font-weight: 600;
    color: #aab3d8;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.element-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}

.element-item {
    padding: 10px;
    background: #1c2250;
    border: 1px solid #2c3268;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    text-align: center;
    transition: all 0.2s;
    color: #e6e9f5;
}

.element-item:hover {
    background: #24306e;
    border-color: #7ea4ff;
    transform: translateY(-2px);
}

.variables-info {
    margin-top: 20px;
    padding: 12px;
    background: #2a2113;
    border-radius: 6px;
    border: 1px solid #5a4a1f;
}

.variables-info h4 {
    font-size: 13px;
    color: #f5c86e;
    margin-bottom: 8px;
}

.variables-info ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.variables-info li {
    font-size: 11px;
    color: #d8c08a;
    margin-bottom: 4px;
}

.variables-info code {
    background: #6b5518;
    color: #ffe1a1;
    padding: 2px 4px;
    border-radius: 3px;
    font-family: monospace;
}

/* Templates Grid */
.templates-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 16px;
}

.btn-block {
    width: 100%;
    justify-content: center;
}

/* Строка состояния под тулбаром: имя открытого шаблона и ошибка загрузки. */
.builder-status {
    flex: none;
    padding: 8px 20px;
    background: #141832;
    border-bottom: 1px solid #262b4d;
    font-size: 12px;
    color: #9aa3c7;
}

.builder-status-error {
    color: #ff8a8a;
    font-weight: 600;
}

.panel-hint {
    margin: 6px 0 0;
    font-size: 12px;
    line-height: 1.5;
    color: #8f98bd;
}

.panel-hint-error {
    color: #ff8a8a;
}

.template-meta {
    margin: 0 0 8px;
    padding: 0 10px;
    font-size: 11px;
    color: #7c86ad;
}

.templates-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
}

.template-card {
    border: 2px solid #2c3268;
    border-radius: 8px;
    overflow: hidden;
    cursor: pointer;
    transition: all 0.2s;
    background: #1a1f45;
}

.template-card:hover {
    border-color: #7ea4ff;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(126, 164, 255, 0.25);
}

.template-card.selected {
    border-color: #7ea4ff;
    box-shadow: 0 0 0 3px rgba(126, 164, 255, 0.25);
}

.template-preview {
    height: 100px;
    position: relative;
    overflow: hidden;
    background: #fff;
}

.preview-element {
    position: absolute;
}

.preview-qr {
    width: 30px;
    height: 30px;
    background: #000;
}

.preview-shape {
    width: 40px;
    height: 40px;
}

.template-name {
    padding: 8px;
    font-size: 12px;
    text-align: center;
    background: #1a1f45;
    color: #dfe4fa;
    margin: 0;
}

/* Canvas Area */
.canvas-area {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #090c1b;
    overflow: auto;
    padding: 20px;
}

.ticket-canvas {
    background: white;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.55);
    position: relative;
    overflow: hidden;
    flex: none;
}

.canvas-inner {
    position: relative;
    background: white;
    transition: transform 0.1s;
}

.grid-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-image: 
        linear-gradient(rgba(59, 130, 246, 0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(59, 130, 246, 0.05) 1px, transparent 1px);
    background-size: 20px 20px;
    pointer-events: none;
}

/* Canvas Elements */
.canvas-element {
    position: absolute;
    cursor: move;
    user-select: none;
}

.canvas-element:hover {
    outline: 2px dashed #3b82f6;
    outline-offset: 2px;
}

.canvas-element.selected {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
}

/* Предпросмотр: убираем всё, что относится только к редактированию —
   сетку выравнивания, ручки ресайза, синие обводки hover/selected.
   Иначе покупателю (и владельцу в режиме проверки) показывают макет с
   разметкой конструктора, а не билет. */
.canvas-inner.is-preview .grid-overlay,
.canvas-inner.is-preview .resize-handle {
    display: none;
}

.canvas-inner.is-preview .canvas-element:hover,
.canvas-inner.is-preview .canvas-element.selected {
    outline: none;
}

.canvas-inner.is-preview {
    cursor: default;
}

/* Ручки ресайза по углам выбранного элемента.
   Размер фиксирован в экранных пикселях: внутри зумированного холста их бы
   масштабировало вместе с билетом. */
.resize-handle {
    position: absolute;
    width: 12px;
    height: 12px;
    background: #3b82f6;
    border: 2px solid #ffffff;
    border-radius: 3px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
    z-index: 5;
}

.resize-nw { top: -8px; left: -8px; cursor: nwse-resize; }
.resize-ne { top: -8px; right: -8px; cursor: nesw-resize; }
.resize-sw { bottom: -8px; left: -8px; cursor: nesw-resize; }
.resize-se { bottom: -8px; right: -8px; cursor: nwse-resize; }

/* Слои: список сверху вниз */
.layers-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.layer-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    background: #1c2250;
    border: 1px solid #2c3268;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    transition: border-color 0.2s;
}

.layer-row:hover {
    border-color: #7ea4ff;
}

.layer-row.selected {
    border-color: #7ea4ff;
    box-shadow: 0 0 0 2px rgba(126, 164, 255, 0.25);
}

.layer-icon {
    flex: none;
    font-size: 14px;
}

.layer-label {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #e6e9f5;
}

.layer-actions {
    flex: none;
    display: flex;
    gap: 4px;
}

.layer-btn {
    width: 26px;
    height: 26px;
    padding: 0;
    border: 1px solid #2c3268;
    border-radius: 4px;
    background: #0f1330;
    color: #dfe4fa;
    cursor: pointer;
    font-size: 13px;
    line-height: 1;
}

.layer-btn:hover:not(:disabled) {
    border-color: #7ea4ff;
}

.layer-btn:disabled {
    opacity: 0.35;
    cursor: default;
}

/* Кнопки порядка в панели свойств */
.layer-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
}

.element-text {
    white-space: nowrap;
    line-height: 1.2;
}

.element-rectangle, .element-circle, .element-line {
    transition: all 0.2s;
}

.element-qr img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

/* Пока локальный рендер не отдал data-URI (canvas рисуется асинхронно),
   показываем рамку, а не пустое место: пустая область выглядит как
   сломанный билет и провоцирует «оно не работает» вместо «секунду». */
.qr-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: repeating-linear-gradient(
        45deg,
        #1c2250,
        #1c2250 6px,
        #141832 6px,
        #141832 12px
    );
    color: #8f98bd;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.08em;
}

.element-barcode, .element-image {
    display: flex;
    align-items: center;
    justify-content: center;
    background: #1c2250;
    border: 1px dashed #3d457e;
}

.element-image-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.barcode-placeholder, .image-placeholder {
    font-size: 10px;
    color: #7c86ad;
    font-weight: 600;
}

/* Properties Panel */
.properties-title {
    padding: 16px;
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: #e6e9f5;
    border-bottom: 1px solid #262b4d;
}

.property-group {
    padding: 16px;
    border-bottom: 1px solid #262b4d;
}

.property-group h4 {
    font-size: 12px;
    color: #8f98bd;
    margin-bottom: 10px;
}

.property-label {
    display: block;
    font-size: 12px;
    color: #aab3d8;
    margin-bottom: 6px;
    margin-top: 10px;
}

/* Чекбокс «Использовать градиент» в одну строку с подписью. */
.property-inline {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #475569;
    margin-top: 4px;
    cursor: pointer;
}

.property-inline input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.property-input, .property-select, .property-textarea {
    width: 100%;
    padding: 8px;
    border: 1px solid #2c3268;
    border-radius: 4px;
    font-size: 13px;
    background: #0f1330;
    color: #e6e9f5;
}

.property-textarea {
    resize: vertical;
    font-family: inherit;
}

.property-input-small {
    width: 70px;
    padding: 6px;
    border: 1px solid #2c3268;
    border-radius: 4px;
    font-size: 12px;
    background: #0f1330;
    color: #e6e9f5;
}

.property-range {
    width: 100%;
    margin-bottom: 4px;
}

.property-value {
    font-size: 11px;
    color: #8f98bd;
    display: block;
    margin-bottom: 8px;
}

.property-color {
    width: 100%;
    height: 36px;
    border: 1px solid #2c3268;
    border-radius: 4px;
    cursor: pointer;
    background: #0f1330;
}

.property-src {
    margin-top: 8px;
}

.property-thumb {
    display: block;
    width: 100%;
    max-height: 140px;
    object-fit: contain;
    margin-top: 8px;
    border-radius: 6px;
    border: 1px solid #2c3268;
    background: #0f1330;
}

.position-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.position-row label {
    font-size: 12px;
    color: #8f98bd;
}

/* Modal */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(4, 6, 18, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal {
    background: #161b3d;
    color: #e6e9f5;
    padding: 24px;
    border-radius: 12px;
    min-width: 400px;
    max-width: min(860px, calc(100vw - 48px));
    max-height: calc(100vh - 96px);
    overflow-y: auto;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
    border: 1px solid #2c3268;
}

.modal-wide {
    width: 760px;
}

.modal h3 {
    margin-bottom: 16px;
    font-size: 18px;
    color: #eef1ff;
}

.modal-input {
    width: 100%;
    padding: 12px;
    border: 1px solid #2c3268;
    border-radius: 6px;
    font-size: 14px;
    margin-bottom: 16px;
    background: #0f1330;
    color: #e6e9f5;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Media Picker */
.media-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}

.media-card {
    padding: 0;
    border: 2px solid #2c3268;
    border-radius: 8px;
    overflow: hidden;
    cursor: pointer;
    background: #0f1330;
    color: #dfe4fa;
    transition: border-color 0.2s, transform 0.2s;
}

.media-card:hover {
    border-color: #7ea4ff;
    transform: translateY(-2px);
}

.media-card img {
    display: block;
    width: 100%;
    height: 110px;
    object-fit: cover;
    background: #090c1b;
}

.media-card span {
    display: block;
    padding: 6px 8px;
    font-size: 11px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Scrollbar */
::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

::-webkit-scrollbar-track {
    background: #0b0e1f;
}

::-webkit-scrollbar-thumb {
    background: #2c3268;
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: #3d457e;
}

/* СВЕТЛАЯ ТЕМА — включается классом `.builder-light`, когда стор темы
   говорит `light` (админка по умолчанию тёмная, поэтому светлая — опция). */
.builder-light.ticket-builder-container {
    background: #f1f5f9;
    color: inherit;
}

.builder-light .builder-toolbar {
    background: #1e293b;
    border-bottom: none;
}

.builder-light .toolbar-label {
    color: #94a3b8;
}

.builder-light .toolbar-select {
    border-color: #475569;
    background: #334155;
    color: white;
}

.builder-light .preview-bar {
    background: #e2e8f0;
    border-bottom-color: #cbd5e1;
}

.builder-light .preview-bar-title {
    color: #1e293b;
}

.builder-light .preview-bar-toggle {
    color: #475569;
}

.builder-light .sidebar {
    background: white;
    border-right-color: #e2e8f0;
}

.builder-light .sidebar-right {
    border-left-color: #e2e8f0;
}

.builder-light .sidebar-tabs {
    border-bottom-color: #e2e8f0;
}

.builder-light .tab-btn {
    color: inherit;
}

.builder-light .tab-btn:hover {
    background: #f1f5f9;
}

.builder-light .tab-btn.active {
    background: #eff6ff;
    border-bottom-color: #3b82f6;
    color: #3b82f6;
}

.builder-light .category-title {
    color: #475569;
}

.builder-light .element-item {
    background: #f1f5f9;
    border-color: #e2e8f0;
    color: inherit;
}

.builder-light .element-item:hover {
    background: #e0f2fe;
    border-color: #3b82f6;
}

.builder-light .variables-info {
    background: #fef3c7;
    border-color: #fde68a;
}

.builder-light .variables-info h4 {
    color: #92400e;
}

.builder-light .variables-info li {
    color: #78350f;
}

.builder-light .variables-info code {
    background: #fbbf24;
    color: inherit;
}

.builder-light .builder-status {
    background: #e2e8f0;
    border-bottom-color: #cbd5e1;
    color: #475569;
}

.builder-light .panel-hint {
    color: #64748b;
}

.builder-light .template-meta {
    color: #94a3b8;
}

.builder-light .template-card {
    border-color: #e2e8f0;
    background: white;
}

.builder-light .template-card:hover,
.builder-light .template-card.selected {
    border-color: #3b82f6;
}

.builder-light .template-name {
    background: #f8fafc;
    color: inherit;
}

.builder-light .canvas-area {
    background: #e2e8f0;
}

.builder-light .ticket-canvas {
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
}

.builder-light .qr-placeholder {
    background: repeating-linear-gradient(45deg, #f1f5f9, #f1f5f9 6px, #e2e8f0 6px, #e2e8f0 12px);
    color: #64748b;
}

.builder-light .element-barcode,
.builder-light .element-image {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.builder-light .barcode-placeholder,
.builder-light .image-placeholder {
    color: #94a3b8;
}

.builder-light .properties-title {
    color: #1e293b;
    border-bottom-color: #e2e8f0;
}

.builder-light .property-group {
    border-bottom-color: #e2e8f0;
}

.builder-light .property-group h4,
.builder-light .property-value,
.builder-light .position-row label {
    color: #64748b;
}

.builder-light .property-label {
    color: #475569;
}

.builder-light .property-input,
.builder-light .property-select,
.builder-light .property-textarea,
.builder-light .property-input-small,
.builder-light .property-color,
.builder-light .modal-input {
    border-color: #e2e8f0;
    background: white;
    color: inherit;
}

.builder-light .property-thumb {
    border-color: #e2e8f0;
    background: white;
}

.builder-light .modal-overlay {
    background: rgba(0, 0, 0, 0.5);
}

.builder-light .modal {
    background: white;
    color: inherit;
    border: none;
}

.builder-light .modal h3 {
    color: #1e293b;
}

.builder-light .media-card {
    border-color: #e2e8f0;
    background: white;
    color: inherit;
}

.builder-light .media-card:hover {
    border-color: #3b82f6;
}

.builder-light .media-card img {
    background: #f1f5f9;
}

.builder-light .layer-row {
    background: #f1f5f9;
    border-color: #e2e8f0;
}

.builder-light .layer-row:hover,
.builder-light .layer-row.selected {
    border-color: #3b82f6;
}

.builder-light .layer-label {
    color: inherit;
}

.builder-light .layer-btn {
    border-color: #e2e8f0;
    background: white;
    color: inherit;
}

.builder-light .layer-btn:hover:not(:disabled) {
    border-color: #3b82f6;
}

.builder-light ::-webkit-scrollbar-track {
    background: #f1f5f9;
}

.builder-light ::-webkit-scrollbar-thumb {
    background: #cbd5e1;
}

.builder-light ::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Печать макета (кнопка «Печать / PDF»).
   На листе остаётся только холст билета: панели, тулбар и сетка конструктора
   при печати превращаются в мусор вокруг макета. Холст принудительно
   показывается в реальном размере без зума и трансформаций — иначе напечатанный
   билет окажется уменьшенным до текущего масштаба редактора. */
@media print {
    .builder-toolbar,
    .preview-bar,
    .builder-status,
    .sidebar,
    .sidebar-left,
    .sidebar-right,
    .sidebar-tabs,
    .panel-content {
        display: none !important;
    }

    .ticket-builder-container {
        height: auto;
        background: #ffffff;
    }

    .builder-main {
        display: block;
        overflow: visible;
        padding: 0;
    }

    .canvas-area {
        padding: 0;
        background: #ffffff;
        overflow: visible;
    }

    .canvas-inner {
        transform: none !important;
    }

    .ticket-canvas {
        transform: none !important;
        box-shadow: none !important;
        margin: 0 auto;
    }
}
</style>
