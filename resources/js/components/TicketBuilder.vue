<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
    templateId: {
        type: [Number, String],
        default: null
    },
    organizationId: {
        type: [Number, String],
        required: true
    }
});

const emit = defineEmits(['template-saved', 'template-deleted']);

// Состояние редактора
const activeTab = ref('elements');
const zoom = ref(100);
const selectedElement = ref(null);
const isDragging = ref(false);
const dragOffset = ref({ x: 0, y: 0 });
const showTemplateModal = ref(false);
const templateName = ref('');
const selectedTemplate = ref(null);

// Размеры холста
const canvasWidth = ref(600);
const canvasHeight = ref(400);

// Элементы на холсте
const elements = ref([]);

// Готовые шаблоны
const templates = ref([
    {
        id: 1,
        name: 'Классический концерт',
        thumbnail: 'classic-concert',
        config: {
            backgroundColor: '#1a1a2e',
            elements: [
                { id: 1, type: 'rectangle', x: 0, y: 0, width: 600, height: 400, fill: '#1a1a2e' },
                { id: 2, type: 'text', x: 50, y: 50, content: 'НАЗВАНИЕ СОБЫТИЯ', fontSize: 32, fontWeight: 'bold', color: '#ffffff' },
                { id: 3, type: 'text', x: 50, y: 100, content: 'Дата и время', fontSize: 18, color: '#e94560' },
                { id: 4, type: 'text', x: 50, y: 150, content: 'Место проведения', fontSize: 16, color: '#cccccc' },
                { id: 5, type: 'qr', x: 400, y: 250, size: 120 },
                { id: 6, type: 'text', x: 50, y: 350, content: 'Место: {{seat.row}} Ряд {{seat.number}}', fontSize: 14, color: '#ffffff' }
            ]
        }
    },
    {
        id: 2,
        name: 'Минимализм',
        thumbnail: 'minimal',
        config: {
            backgroundColor: '#ffffff',
            elements: [
                { id: 1, type: 'rectangle', x: 0, y: 0, width: 600, height: 400, fill: '#ffffff' },
                { id: 2, type: 'line', x1: 0, y1: 80, x2: 600, y2: 80, stroke: '#000000', strokeWidth: 2 },
                { id: 3, type: 'text', x: 40, y: 40, content: 'EVENT NAME', fontSize: 28, fontWeight: 'bold', color: '#000000' },
                { id: 4, type: 'text', x: 40, y: 120, content: '{{event.name}}', fontSize: 16, color: '#333333' },
                { id: 5, type: 'text', x: 40, y: 160, content: '{{event.date}}', fontSize: 14, color: '#666666' },
                { id: 6, type: 'qr', x: 420, y: 200, size: 150 },
                { id: 7, type: 'text', x: 40, y: 350, content: '{{ticket.holder}}', fontSize: 18, color: '#000000' }
            ]
        }
    },
    {
        id: 3,
        name: 'Фестиваль',
        thumbnail: 'festival',
        config: {
            backgroundColor: '#ff6b6b',
            elements: [
                { id: 1, type: 'rectangle', x: 0, y: 0, width: 600, height: 400, fill: '#ff6b6b' },
                { id: 2, type: 'circle', cx: 500, cy: 80, r: 60, fill: '#feca57' },
                { id: 3, type: 'text', x: 30, y: 60, content: 'FESTIVAL', fontSize: 36, fontWeight: 'bold', color: '#ffffff' },
                { id: 4, type: 'text', x: 30, y: 100, content: '2024', fontSize: 48, fontWeight: 'bold', color: '#feca57' },
                { id: 5, type: 'text', x: 30, y: 200, content: '{{event.name}}', fontSize: 20, color: '#ffffff' },
                { id: 6, type: 'qr', x: 380, y: 220, size: 140 },
                { id: 7, type: 'text', x: 30, y: 350, content: 'Ticket #{{ticket.number}}', fontSize: 14, color: '#ffffff' }
            ]
        }
    },
    {
        id: 4,
        name: 'Театральный',
        thumbnail: 'theater',
        config: {
            backgroundColor: '#2c3e50',
            elements: [
                { id: 1, type: 'rectangle', x: 0, y: 0, width: 600, height: 400, fill: '#2c3e50' },
                { id: 2, type: 'rectangle', x: 20, y: 20, width: 560, height: 360, fill: 'transparent', stroke: '#d4af37', strokeWidth: 3 },
                { id: 3, type: 'text', x: 50, y: 80, content: 'ПРЕМЬЕРА', fontSize: 24, fontWeight: 'bold', color: '#d4af37' },
                { id: 4, type: 'text', x: 50, y: 130, content: '{{event.name}}', fontSize: 22, color: '#ecf0f1' },
                { id: 5, type: 'text', x: 50, y: 180, content: '{{event.venue}}', fontSize: 16, color: '#bdc3c7' },
                { id: 6, type: 'qr', x: 400, y: 240, size: 130 },
                { id: 7, type: 'text', x: 50, y: 350, content: 'Место: {{seat.info}}', fontSize: 14, color: '#ecf0f1' }
            ]
        }
    },
    {
        id: 5,
        name: 'Деловой',
        thumbnail: 'business',
        config: {
            backgroundColor: '#f8f9fa',
            elements: [
                { id: 1, type: 'rectangle', x: 0, y: 0, width: 600, height: 400, fill: '#f8f9fa' },
                { id: 2, type: 'rectangle', x: 0, y: 0, width: 600, height: 80, fill: '#343a40' },
                { id: 3, type: 'text', x: 40, y: 50, content: 'BUSINESS EVENT', fontSize: 24, fontWeight: 'bold', color: '#ffffff' },
                { id: 4, type: 'text', x: 40, y: 120, content: '{{event.name}}', fontSize: 18, color: '#212529' },
                { id: 5, type: 'text', x: 40, y: 160, content: '{{event.date}} | {{event.time}}', fontSize: 14, color: '#6c757d' },
                { id: 6, type: 'line', x1: 40, y1: 200, x2: 560, y2: 200, stroke: '#dee2e6', strokeWidth: 1 },
                { id: 7, type: 'qr', x: 420, y: 230, size: 120 },
                { id: 8, type: 'text', x: 40, y: 250, content: '{{ticket.holder}}', fontSize: 16, color: '#212529' },
                { id: 9, type: 'text', x: 40, y: 280, content: '{{seat.info}}', fontSize: 14, color: '#495057' }
            ]
        }
    }
]);

// Переменные для подстановки данных
const ticketVariables = {
    event: {
        name: 'Название события',
        date: '01.01.2024',
        time: '19:00',
        venue: 'Концертный зал'
    },
    ticket: {
        number: 'A001234',
        holder: 'Иван Иванов'
    },
    seat: {
        row: '5',
        number: '12',
        info: 'Ряд 5, Место 12'
    }
};

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
    const newElement = {
        id: Date.now(),
        type: item.type,
        x: 50,
        y: 50,
        ...item.defaultData,
        rotation: 0,
        opacity: 1
    };
    
    // Установка размеров по умолчанию для разных типов
    if (item.type === 'text' && !newElement.width) {
        newElement.width = 200;
        newElement.height = 30;
    } else if (item.type === 'rectangle' && !newElement.x2) {
        newElement.x2 = newElement.x + newElement.width;
        newElement.y2 = newElement.y + newElement.height;
    } else if (item.type === 'circle') {
        newElement.cx = newElement.x + (item.defaultData?.r || 50);
        newElement.cy = newElement.y + (item.defaultData?.r || 50);
    } else if (item.type === 'line') {
        newElement.x2 = newElement.x + (item.defaultData?.x2 || 200);
        newElement.y2 = newElement.y;
    } else if (item.type === 'qr' || item.type === 'barcode') {
        newElement.width = item.defaultData?.size || 100;
        newElement.height = item.type === 'qr' ? item.defaultData?.size || 100 : item.defaultData?.height || 50;
    }
    
    elements.value.push(newElement);
    selectElement(newElement);
}

// Выбор элемента
function selectElement(element) {
    selectedElement.value = element;
}

// Начало перетаскивания
function startDrag(event, element) {
    isDragging.value = true;
    selectedElement.value = element;
    
    const rect = event.target.getBoundingClientRect();
    dragOffset.value = {
        x: event.clientX - rect.left,
        y: event.clientY - rect.top
    };
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
    
    // Обновление связанных координат
    if (selectedElement.value.type === 'circle') {
        selectedElement.value.cx = selectedElement.value.x + (selectedElement.value.r || 50);
        selectedElement.value.cy = selectedElement.value.y + (selectedElement.value.r || 50);
    } else if (selectedElement.value.type === 'line') {
        selectedElement.value.x2 = selectedElement.value.x + 200;
    } else if (selectedElement.value.type === 'rectangle' || selectedElement.value.type === 'image') {
        selectedElement.value.x2 = selectedElement.value.x + (selectedElement.value.width || 100);
        selectedElement.value.y2 = selectedElement.value.y + (selectedElement.value.height || 100);
    }
}

// Завершение перетаскивания
function stopDrag() {
    isDragging.value = false;
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

// Дублирование элемента
function duplicateElement() {
    if (!selectedElement.value) return;
    const duplicate = {
        ...JSON.parse(JSON.stringify(selectedElement.value)),
        id: Date.now(),
        x: selectedElement.value.x + 20,
        y: selectedElement.value.y + 20
    };
    elements.value.push(duplicate);
    selectElement(duplicate);
}

// Применение шаблона
function applyTemplate(template) {
    selectedTemplate.value = template;
    canvasWidth.value = 600;
    canvasHeight.value = 400;
    elements.value = JSON.parse(JSON.stringify(template.config.elements));
    selectedElement.value = null;
}

// Открытие модального окна сохранения
function openSaveModal() {
    templateName.value = '';
    showTemplateModal.value = true;
}

// Сохранение шаблона
async function saveTemplate() {
    if (!templateName.value.trim()) {
        alert('Введите название шаблона');
        return;
    }
    
    try {
        const payload = {
            name: templateName.value,
            organization_id: props.organizationId,
            format: 'mobile',
            width: canvasWidth.value,
            height: canvasHeight.value,
            template_json: JSON.stringify({
                backgroundColor: '#ffffff',
                elements: elements.value
            })
        };
        
        const response = await axios.post('/api/ticket-templates', payload);
        
        if (response.data.success || response.data.id) {
            emit('template-saved', response.data);
            showTemplateModal.value = false;
            alert('Шаблон успешно сохранён!');
        } else {
            throw new Error('Ошибка сохранения');
        }
    } catch (error) {
        console.error('Save error:', error);
        alert('Ошибка при сохранении шаблона: ' + (error.response?.data?.message || error.message));
    }
}

// Загрузка существующего шаблона
async function loadTemplate() {
    if (!props.templateId) return;
    
    try {
        const response = await axios.get(`/api/ticket-templates/${props.templateId}`);
        const template = response.data;
        
        if (template.template_json) {
            const config = JSON.parse(template.template_json);
            canvasWidth.value = template.width || 600;
            canvasHeight.value = template.height || 400;
            elements.value = config.elements || [];
        }
    } catch (error) {
        console.error('Load error:', error);
    }
}

// Отрисовка переменной текста
function renderTextContent(content) {
    if (!content) return '';
    
    let rendered = content;
    
    // Замена переменных
    rendered = rendered.replace(/\{\{event\.name\}\}/g, ticketVariables.event.name);
    rendered = rendered.replace(/\{\{event\.date\}\}/g, ticketVariables.event.date);
    rendered = rendered.replace(/\{\{event\.time\}\}/g, ticketVariables.event.time);
    rendered = rendered.replace(/\{\{event\.venue\}\}/g, ticketVariables.event.venue);
    rendered = rendered.replace(/\{\{ticket\.number\}\}/g, ticketVariables.ticket.number);
    rendered = rendered.replace(/\{\{ticket\.holder\}\}/g, ticketVariables.ticket.holder);
    rendered = rendered.replace(/\{\{seat\.row\}\}/g, ticketVariables.seat.row);
    rendered = rendered.replace(/\{\{seat\.number\}\}/g, ticketVariables.seat.number);
    rendered = rendered.replace(/\{\{seat\.info\}\}/g, ticketVariables.seat.info);
    
    return rendered;
}

// Генерация URL для QR кода
function getQrCodeUrl(data) {
    const qrData = encodeURIComponent(data || 'https://nabilet.com');
    return `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${qrData}`;
}

// Экспорт в PDF
async function exportToPDF() {
    try {
        const response = await axios.post('/api/tickets/generate-preview', {
            elements: elements.value,
            width: canvasWidth.value,
            height: canvasHeight.value,
            variables: ticketVariables
        }, {
            responseType: 'blob'
        });
        
        const blob = new Blob([response.data], { type: 'application/pdf' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `ticket_preview_${Date.now()}.pdf`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    } catch (error) {
        console.error('PDF export error:', error);
        alert('Ошибка при генерации PDF');
    }
}

// Изменение размера холста
function resizeCanvas(preset) {
    const sizes = {
        mobile: { width: 400, height: 600 },
        desktop: { width: 600, height: 400 },
        square: { width: 500, height: 500 },
        wide: { width: 800, height: 400 }
    };
    
    canvasWidth.value = sizes[preset].width;
    canvasHeight.value = sizes[preset].height;
}

onMounted(() => {
    if (props.templateId) {
        loadTemplate();
    }
});
</script>

<template>
    <div class="ticket-builder-container">
        <!-- Toolbar -->
        <div class="builder-toolbar">
            <div class="toolbar-section">
                <button @click="openSaveModal" class="btn btn-primary">
                    💾 Сохранить шаблон
                </button>
                <button @click="exportToPDF" class="btn btn-success">
                    📥 Экспорт PDF
                </button>
                <button @click="deleteSelected" :disabled="!selectedElement" class="btn btn-danger">
                    🗑️ Удалить
                </button>
                <button @click="duplicateElement" :disabled="!selectedElement" class="btn btn-secondary">
                    📋 Дублировать
                </button>
            </div>
            
            <div class="toolbar-section">
                <label class="toolbar-label">Размер:</label>
                <select @change="resizeCanvas($event.target.value)" class="toolbar-select">
                    <option value="mobile">Мобильный (400×600)</option>
                    <option value="desktop" selected>Десктоп (600×400)</option>
                    <option value="square">Квадрат (500×500)</option>
                    <option value="wide">Широкий (800×400)</option>
                </select>
            </div>
            
            <div class="toolbar-section">
                <button @click="zoom = Math.max(zoom - 10, 50)" class="btn btn-icon">−</button>
                <span class="zoom-value">{{ zoom }}%</span>
                <button @click="zoom = Math.min(zoom + 10, 200)" class="btn btn-icon">+</button>
            </div>
        </div>
        
        <div class="builder-main">
            <!-- Left Sidebar - Elements -->
            <div class="sidebar sidebar-left">
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
                    
                    <div class="variables-info">
                        <h4>📋 Доступные переменные:</h4>
                        <ul>
                            <li><code>{{event.name}}</code> - Название события</li>
                            <li><code>{{event.date}}</code> - Дата</li>
                            <li><code>{{event.time}}</code> - Время</li>
                            <li><code>{{event.venue}}</code> - Место проведения</li>
                            <li><code>{{ticket.number}}</code> - Номер билета</li>
                            <li><code>{{ticket.holder}}</code> - Владелец</li>
                            <li><code>{{seat.row}}</code> - Ряд</li>
                            <li><code>{{seat.number}}</code> - Место</li>
                            <li><code>{{seat.info}}</code> - Информация о месте</li>
                        </ul>
                    </div>
                </div>
                
                <!-- Templates Panel -->
                <div v-if="activeTab === 'templates'" class="panel-content">
                    <div class="templates-grid">
                        <div 
                            v-for="template in templates" 
                            :key="template.id"
                            @click="applyTemplate(template)"
                            class="template-card"
                            :class="{ selected: selectedTemplate?.id === template.id }"
                        >
                            <div class="template-preview" :style="{ background: template.config.backgroundColor }">
                                <div v-for="el in template.config.elements" :key="el.id" class="preview-element">
                                    <span v-if="el.type === 'text'" :style="{ 
                                        fontSize: `${el.fontSize / 3}px`,
                                        color: el.color,
                                        fontWeight: el.fontWeight
                                    }">{{ el.content.substring(0, 15) }}...</span>
                                    <div v-if="el.type === 'qr'" class="preview-qr"></div>
                                    <div v-if="el.type === 'rectangle'" class="preview-shape" :style="{ background: el.fill }"></div>
                                </div>
                            </div>
                            <p class="template-name">{{ template.name }}</p>
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
                    @mousemove="handleDrag"
                    @mouseup="stopDrag"
                    @mouseleave="stopDrag"
                >
                    <div 
                        class="canvas-inner"
                        :style="{ 
                            width: `${canvasWidth}px`,
                            height: `${canvasHeight}px`,
                            transform: `scale(${zoom / 100})`,
                            transformOrigin: 'top left'
                        }"
                    >
                        <!-- Rendered Elements -->
                        <div 
                            v-for="element in elements" 
                            :key="element.id"
                            class="canvas-element"
                            :class="{ selected: selectedElement?.id === element.id }"
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
                                    color: element.color,
                                    width: 'auto'
                                }"
                            >
                                {{ renderTextContent(element.content) }}
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
                                    borderRadius: element.borderRadius || '0px'
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
                            
                            <!-- QR Code Element -->
                            <div 
                                v-if="element.type === 'qr'"
                                class="element-qr"
                                :style="{ width: `${element.size}px`, height: `${element.size}px` }"
                            >
                                <img :src="getQrCodeUrl(element.data)" alt="QR Code" />
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
                            
                            <!-- Image Element -->
                            <div 
                                v-if="element.type === 'image'"
                                class="element-image"
                                :style="{ width: `${element.width}px`, height: `${element.height}px` }"
                            >
                                <div class="image-placeholder">
                                    IMG
                                </div>
                            </div>
                        </div>
                        
                        <!-- Grid overlay for alignment -->
                        <div class="grid-overlay"></div>
                    </div>
                </div>
            </div>
            
            <!-- Right Sidebar - Properties -->
            <div v-if="selectedElement" class="sidebar sidebar-right">
                <h3 class="properties-title">Свойства</h3>
                
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
                    
                    <label class="property-label">Цвет:</label>
                    <input type="color" v-model="selectedElement.color" class="property-color" />
                    
                    <label class="property-label">Жирный:</label>
                    <select v-model="selectedElement.fontWeight" class="property-select">
                        <option value="normal">Обычный</option>
                        <option value="bold">Жирный</option>
                    </select>
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
                    <span class="property-value">{{ Math.round(selectedElement.opacity * 100) }}%</span>
                    
                    <label class="property-label">Вращение:</label>
                    <input 
                        type="range" 
                        v-model.number="selectedElement.rotation" 
                        min="0" 
                        max="360"
                        class="property-range"
                    />
                    <span class="property-value">{{ selectedElement.rotation }}°</span>
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
    </div>
</template>

<style scoped>
.ticket-builder-container {
    display: flex;
    flex-direction: column;
    height: 100vh;
    background: #f1f5f9;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

/* Toolbar */
.builder-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 20px;
    background: #1e293b;
    color: white;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.toolbar-section {
    display: flex;
    align-items: center;
    gap: 10px;
}

.toolbar-label {
    font-size: 13px;
    color: #94a3b8;
}

.toolbar-select {
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid #475569;
    background: #334155;
    color: white;
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

/* Main Area */
.builder-main {
    display: flex;
    flex: 1;
    overflow: hidden;
}

/* Sidebars */
.sidebar {
    width: 280px;
    background: white;
    border-right: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
}

.sidebar-right {
    border-right: none;
    border-left: 1px solid #e2e8f0;
}

.sidebar-tabs {
    display: flex;
    border-bottom: 1px solid #e2e8f0;
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
}

.tab-btn:hover {
    background: #f1f5f9;
}

.tab-btn.active {
    background: #eff6ff;
    border-bottom-color: #3b82f6;
    color: #3b82f6;
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
    color: #475569;
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
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    text-align: center;
    transition: all 0.2s;
}

.element-item:hover {
    background: #e0f2fe;
    border-color: #3b82f6;
    transform: translateY(-2px);
}

.variables-info {
    margin-top: 20px;
    padding: 12px;
    background: #fef3c7;
    border-radius: 6px;
    border: 1px solid #fde68a;
}

.variables-info h4 {
    font-size: 13px;
    color: #92400e;
    margin-bottom: 8px;
}

.variables-info ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.variables-info li {
    font-size: 11px;
    color: #78350f;
    margin-bottom: 4px;
}

.variables-info code {
    background: #fbbf24;
    padding: 2px 4px;
    border-radius: 3px;
    font-family: monospace;
}

/* Templates Grid */
.templates-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
}

.template-card {
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
    cursor: pointer;
    transition: all 0.2s;
}

.template-card:hover {
    border-color: #3b82f6;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
}

.template-card.selected {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
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
    background: #f8fafc;
    margin: 0;
}

/* Canvas Area */
.canvas-area {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e2e8f0;
    overflow: auto;
    padding: 20px;
}

.ticket-canvas {
    background: white;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    position: relative;
    overflow: hidden;
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

.element-barcode, .element-image {
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f1f5f9;
    border: 1px dashed #cbd5e1;
}

.barcode-placeholder, .image-placeholder {
    font-size: 10px;
    color: #94a3b8;
    font-weight: 600;
}

/* Properties Panel */
.properties-title {
    padding: 16px;
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
    border-bottom: 1px solid #e2e8f0;
}

.property-group {
    padding: 16px;
    border-bottom: 1px solid #e2e8f0;
}

.property-group h4 {
    font-size: 12px;
    color: #64748b;
    margin-bottom: 10px;
}

.property-label {
    display: block;
    font-size: 12px;
    color: #475569;
    margin-bottom: 6px;
    margin-top: 10px;
}

.property-input, .property-select, .property-textarea {
    width: 100%;
    padding: 8px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 13px;
    background: white;
}

.property-textarea {
    resize: vertical;
    font-family: inherit;
}

.property-input-small {
    width: 70px;
    padding: 6px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 12px;
}

.property-range {
    width: 100%;
    margin-bottom: 4px;
}

.property-value {
    font-size: 11px;
    color: #64748b;
    display: block;
    margin-bottom: 8px;
}

.property-color {
    width: 100%;
    height: 36px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    cursor: pointer;
}

.position-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.position-row label {
    font-size: 12px;
    color: #64748b;
}

/* Modal */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal {
    background: white;
    padding: 24px;
    border-radius: 12px;
    min-width: 400px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
}

.modal h3 {
    margin-bottom: 16px;
    font-size: 18px;
    color: #1e293b;
}

.modal-input {
    width: 100%;
    padding: 12px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 14px;
    margin-bottom: 16px;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Scrollbar */
::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

::-webkit-scrollbar-track {
    background: #f1f5f9;
}

::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>
