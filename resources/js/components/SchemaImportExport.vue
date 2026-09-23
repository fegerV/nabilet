<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';

const emit = defineEmits(['import-success']);

const fileInput = ref(null);
const isImporting = ref(false);
const importStatus = ref('');
const exportData = ref(null);

// Импорт схемы из JSON файла
async function importSchema(event) {
    const file = event.target.files[0];
    if (!file) return;

    isImporting.value = true;
    importStatus.value = 'Чтение файла...';

    try {
        const reader = new FileReader();
        
        reader.onload = async (e) => {
            try {
                const schemaData = JSON.parse(e.target.result);
                
                // Валидация структуры
                if (!schemaData.layout || !Array.isArray(schemaData.layout.seats)) {
                    throw new Error('Неверный формат файла: отсутствует структура layout.seats');
                }

                importStatus.value = 'Отправка на сервер...';
                
                // Отправка на сервер для сохранения
                const response = await axios.post('/api/venues/schemas/import', {
                    schema: schemaData,
                    venue_id: window.venueId || null
                });

                importStatus.value = 'Успешно импортировано!';
                emit('import-success', response.data.schema);
                
                // Очистка input
                if (fileInput.value) {
                    fileInput.value.value = '';
                }
            } catch (error) {
                importStatus.value = `Ошибка импорта: ${error.message}`;
            } finally {
                isImporting.value = false;
            }
        };

        reader.onerror = () => {
            importStatus.value = 'Ошибка чтения файла';
            isImporting.value = false;
        };

        reader.readAsText(file);
    } catch (error) {
        importStatus.value = `Ошибка: ${error.message}`;
        isImporting.value = false;
    }
}

// Экспорт текущей схемы в JSON
async function exportSchema(schemaData, schemaName) {
    try {
        const exportPayload = {
            name: schemaName || 'Экспортированная схема',
            version: '1.0',
            exported_at: new Date().toISOString(),
            layout: schemaData,
            total_seats: schemaData.seats?.length || 0,
            metadata: {
                rows: schemaData.rows?.length || 0,
                seat_types: [...new Set(schemaData.seats?.map(s => s.type) || [])]
            }
        };

        // Создание и скачивание файла
        const blob = new Blob([JSON.stringify(exportPayload, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `hall_schema_${schemaName || 'export'}_${Date.now()}.json`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);

        return exportPayload;
    } catch (error) {
        console.error('Export error:', error);
        throw error;
    }
}

// Загрузка схемы с сервера для экспорта
async function loadSchemaForExport(schemaId) {
    try {
        const response = await axios.get(`/api/venues/schemas/${schemaId}`);
        return exportSchema(response.data.layout_data, response.data.name);
    } catch (error) {
        console.error('Failed to load schema for export:', error);
        throw error;
    }
}
</script>

<template>
    <div class="schema-import-export">
        <!-- Кнопки управления -->
        <div class="flex gap-2 mb-4">
            <button
                @click="$refs.fileInput.click()"
                :disabled="isImporting"
                class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50"
            >
                📥 Импорт схемы
            </button>
            
            <button
                @click="$emit('export')"
                class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
            >
                📤 Экспорт схемы
            </button>

            <input
                ref="fileInput"
                type="file"
                accept=".json,application/json"
                @change="importSchema"
                class="hidden"
            />
        </div>

        <!-- Статус операции -->
        <div v-if="importStatus" class="mt-2 p-3 rounded bg-gray-100 text-sm">
            <span :class="{
                'text-green-600': importStatus.includes('Успешно'),
                'text-red-600': importStatus.includes('Ошибка'),
                'text-blue-600': !importStatus.includes('Успешно') && !importStatus.includes('Ошибка')
            }">
                {{ importStatus }}
            </span>
            <span v-if="isImporting" class="ml-2 animate-spin">⏳</span>
        </div>

        <!-- Информация о формате -->
        <div class="mt-4 p-4 bg-blue-50 rounded text-xs text-gray-600">
            <h4 class="font-bold mb-2">Формат файла импорта:</h4>
            <pre class="overflow-auto bg-white p-2 rounded border">{{ `{
  "name": "Название зала",
  "layout": {
    "rows": [{"id": 1, "name": "Ряд 1"}],
    "seats": [
      {"id": 1, "row": 1, "number": 1, "type": "standard", "x": 0, "y": 0}
    ]
  },
  "total_seats": 100
}` }}</pre>
        </div>
    </div>
</template>

<style scoped>
.schema-import-export {
    font-family: inherit;
}
</style>
