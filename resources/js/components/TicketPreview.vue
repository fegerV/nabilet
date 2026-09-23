<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
    booking: {
        type: Object,
        required: true
    }
});

const isGenerating = ref(false);
const ticketUrl = ref(null);
const qrCodeData = ref(null);

// Данные билета
const ticketData = computed(() => {
    if (!props.booking) return null;
    
    const event = props.booking.event;
    const seats = props.booking.seats || [];
    
    return {
        order_id: props.booking.id,
        event_name: event?.name || 'Событие',
        event_date: event?.start_at ? new Date(event.start_at).toLocaleString('ru-RU') : '',
        venue_name: event?.venue?.name || '',
        seats: seats.map(seat => `Ряд ${seat.row}, Место ${seat.number}`).join(', '),
        price: props.booking.total_price || 0,
        customer_name: props.booking.user?.name || 'Гость',
        customer_email: props.booking.user?.email || '',
        unique_hash: props.booking.hash || props.booking.id,
    };
});

// Генерация QR кода
function generateQrCode() {
    if (!props.booking) return;
    
    const qrData = JSON.stringify({
        order_id: props.booking.id,
        hash: props.booking.hash,
        event_id: props.booking.event_id,
        timestamp: Date.now()
    });
    
    // Используем API для генерации QR или сторонний сервис
    qrCodeData.value = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(qrData)}`;
}

// Скачивание билета в PDF
async function downloadTicket() {
    isGenerating.value = true;
    
    try {
        const response = await axios.post('/api/tickets/generate', {
            booking_id: props.booking.id
        }, {
            responseType: 'blob'
        });
        
        const blob = new Blob([response.data], { type: 'application/pdf' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `ticket_${props.booking.id}.pdf`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        
        ticketUrl.value = url;
    } catch (error) {
        console.error('Failed to generate ticket:', error);
        alert('Ошибка при генерации билета. Попробуйте позже.');
    } finally {
        isGenerating.value = false;
    }
}

// Отправка билета на email
async function sendToEmail() {
    try {
        await axios.post('/api/tickets/send-email', {
            booking_id: props.booking.id
        });
        alert('Билет отправлен на email');
    } catch (error) {
        console.error('Failed to send ticket:', error);
        alert('Ошибка при отправке билета');
    }
}

onMounted(() => {
    generateQrCode();
});
</script>

<template>
    <div class="ticket-preview max-w-2xl mx-auto p-6 bg-white rounded-lg shadow-lg">
        <!-- Header -->
        <div class="bg-gradient-to-r from-purple-600 to-indigo-600 text-white p-6 rounded-t-lg -m-6 mb-6">
            <h2 class="text-2xl font-bold">{{ ticketData?.event_name }}</h2>
            <p class="mt-2 opacity-90">{{ ticketData?.event_date }}</p>
            <p v-if="ticketData?.venue_name" class="opacity-80">📍 {{ ticketData.venue_name }}</p>
        </div>

        <!-- Customer Info -->
        <div class="mb-6 p-4 bg-gray-50 rounded-lg">
            <p class="text-xs text-gray-500 uppercase mb-1">Билет для</p>
            <p class="font-bold text-lg">{{ ticketData?.customer_name }}</p>
            <p class="text-sm text-gray-600">{{ ticketData?.customer_email }}</p>
        </div>

        <!-- Seats Info -->
        <div class="mb-6 p-4 bg-blue-50 rounded-lg border-l-4 border-blue-500">
            <p class="text-xs text-blue-600 uppercase mb-1">Места</p>
            <p class="font-bold text-blue-800">{{ ticketData?.seats }}</p>
        </div>

        <!-- Price -->
        <div class="mb-6 text-right">
            <span class="text-gray-600">Общая стоимость: </span>
            <span class="text-2xl font-bold text-green-600">{{ ticketData?.price }} ₽</span>
        </div>

        <!-- QR Code -->
        <div class="mb-6 text-center p-6 bg-white border-2 border-dashed border-gray-300 rounded-lg">
            <img 
                v-if="qrCodeData" 
                :src="qrCodeData" 
                alt="QR Code" 
                class="mx-auto max-w-[200px] h-auto"
            />
            <p class="mt-3 text-sm text-gray-600">Покажите этот код на входе</p>
            <p class="text-xs text-gray-400">Заказ №{{ ticketData?.order_id }} | {{ ticketData?.unique_hash?.toUpperCase() }}</p>
        </div>

        <!-- Actions -->
        <div class="flex gap-3 justify-center">
            <button
                @click="downloadTicket"
                :disabled="isGenerating"
                class="px-6 py-3 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 flex items-center gap-2"
            >
                <span v-if="isGenerating">⏳</span><span v-else>📥</span>
                {{ isGenerating ? 'Генерация...' : 'Скачать PDF' }}
            </button>
            
            <button
                @click="sendToEmail"
                class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center gap-2"
            >
                ✉️ Отправить на Email
            </button>
        </div>

        <!-- Footer Note -->
        <p class="mt-6 text-center text-xs text-gray-500">
            Сохраните этот билет на устройстве или распечатайте его для входа на мероприятие.
        </p>
    </div>
</template>

<style scoped>
.ticket-preview {
    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
}
</style>
