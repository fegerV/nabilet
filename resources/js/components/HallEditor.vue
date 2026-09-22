<template>
  <div class="hall-editor-container">
    <!-- Toolbar -->
    <div class="editor-toolbar">
      <button @click="addSeat" class="tool-btn">Добавить место</button>
      <button @click="addRow" class="tool-btn">Добавить ряд</button>
      <button @click="deleteSelected" class="tool-btn danger">Удалить выбранное</button>
      <button @click="saveSchema" class="tool-btn primary">Сохранить схему</button>
      <div class="zoom-controls">
        <button @click="zoomOut">−</button>
        <span>{{ zoom }}%</span>
        <button @click="zoomIn">+</button>
      </div>
    </div>

    <!-- Canvas -->
    <div class="editor-canvas" ref="canvas" @mousedown="startSelection" @mousemove="updateSelection" @mouseup="endSelection">
      <div :style="{ transform: `scale(${zoom / 100})`, transformOrigin: 'top left' }">
        <!-- Rows and Seats -->
        <div v-for="(row, rowIndex) in schema.rows" :key="row.id" class="hall-row">
          <div class="row-label">Ряд {{ rowIndex + 1 }}</div>
          <div class="seats-container">
            <div 
              v-for="seat in row.seats" 
              :key="seat.id"
              class="seat"
              :class="{ 
                'selected': selectedSeats.includes(seat.id),
                'vip': seat.type === 'vip',
                'standard': seat.type === 'standard'
              }"
              @click="selectSeat(seat)"
              @contextmenu.prevent="openSeatMenu(seat, $event)"
            >
              {{ seat.number }}
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Seat Context Menu -->
    <div v-if="contextMenu.visible" class="context-menu" :style="{ top: contextMenu.y + 'px', left: contextMenu.x + 'px' }">
      <button @click="setSeatType('standard')">Обычное</button>
      <button @click="setSeatType('vip')">VIP</button>
      <button @click="deleteSeat">Удалить</button>
      <button @click="closeContextMenu">Закрыть</button>
    </div>

    <!-- Save Modal -->
    <div v-if="showSaveModal" class="modal-overlay">
      <div class="modal">
        <h3>Сохранение схемы зала</h3>
        <input v-model="schemaName" type="text" placeholder="Название схемы" class="input-field" />
        <div class="modal-actions">
          <button @click="cancelSave" class="btn-secondary">Отмена</button>
          <button @click="confirmSave" class="btn-primary">Сохранить</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import axios from 'axios';

export default {
  name: 'HallEditor',
  props: {
    venueId: {
      type: [Number, String],
      default: null
    },
    existingSchema: {
      type: Object,
      default: () => ({ rows: [] })
    }
  },
  data() {
    return {
      schema: {
        rows: []
      },
      zoom: 100,
      selectedSeats: [],
      contextMenu: {
        visible: false,
        x: 0,
        y: 0,
        currentSeat: null
      },
      showSaveModal: false,
      schemaName: '',
      isSelecting: false,
      selectionStart: { x: 0, y: 0 }
    };
  },
  mounted() {
    if (this.existingSchema && this.existingSchema.rows.length > 0) {
      this.schema = JSON.parse(JSON.stringify(this.existingSchema));
    }
  },
  methods: {
    addRow() {
      const newRow = {
        id: Date.now(),
        seats: []
      };
      
      // Add 10 standard seats by default
      for (let i = 1; i <= 10; i++) {
        newRow.seats.push({
          id: Date.now() + i,
          number: i,
          type: 'standard',
          row: this.schema.rows.length + 1
        });
      }
      
      this.schema.rows.push(newRow);
    },
    
    addSeat() {
      if (this.schema.rows.length === 0) {
        alert('Сначала добавьте ряд');
        return;
      }
      
      const lastRow = this.schema.rows[this.schema.rows.length - 1];
      const nextNumber = lastRow.seats.length > 0 
        ? Math.max(...lastRow.seats.map(s => s.number)) + 1 
        : 1;
      
      lastRow.seats.push({
        id: Date.now(),
        number: nextNumber,
        type: 'standard',
        row: this.schema.rows.length
      });
    },
    
    selectSeat(seat, event) {
      if (event && event.ctrlKey) {
        const index = this.selectedSeats.indexOf(seat.id);
        if (index > -1) {
          this.selectedSeats.splice(index, 1);
        } else {
          this.selectedSeats.push(seat.id);
        }
      } else {
        this.selectedSeats = [seat.id];
      }
    },
    
    deleteSelected() {
      if (this.selectedSeats.length === 0) {
        alert('Выберите места для удаления');
        return;
      }
      
      this.schema.rows.forEach(row => {
        row.seats = row.seats.filter(seat => !this.selectedSeats.includes(seat.id));
      });
      
      this.selectedSeats = [];
    },
    
    openSeatMenu(seat, event) {
      this.contextMenu.currentSeat = seat;
      this.contextMenu.x = event.clientX;
      this.contextMenu.y = event.clientY;
      this.contextMenu.visible = true;
    },
    
    closeContextMenu() {
      this.contextMenu.visible = false;
      this.contextMenu.currentSeat = null;
    },
    
    setSeatType(type) {
      if (this.contextMenu.currentSeat) {
        const seat = this.findSeat(this.contextMenu.currentSeat.id);
        if (seat) {
          seat.type = type;
        }
      }
      this.closeContextMenu();
    },
    
    deleteSeat() {
      if (this.contextMenu.currentSeat) {
        const seatId = this.contextMenu.currentSeat.id;
        this.schema.rows.forEach(row => {
          row.seats = row.seats.filter(s => s.id !== seatId);
        });
      }
      this.closeContextMenu();
    },
    
    findSeat(seatId) {
      for (const row of this.schema.rows) {
        const seat = row.seats.find(s => s.id === seatId);
        if (seat) return seat;
      }
      return null;
    },
    
    zoomIn() {
      this.zoom = Math.min(this.zoom + 10, 200);
    },
    
    zoomOut() {
      this.zoom = Math.max(this.zoom - 10, 50);
    },
    
    startSelection(event) {
      if (event.button === 0) {
        this.isSelecting = true;
        this.selectionStart = { x: event.clientX, y: event.clientY };
      }
    },
    
    updateSelection(event) {
      if (!this.isSelecting) return;
      // Rectangle selection logic can be added here
    },
    
    endSelection() {
      this.isSelecting = false;
    },
    
    saveSchema() {
      this.schemaName = '';
      this.showSaveModal = true;
    },
    
    cancelSave() {
      this.showSaveModal = false;
      this.schemaName = '';
    },
    
    async confirmSave() {
      if (!this.schemaName.trim()) {
        alert('Введите название схемы');
        return;
      }
      
      try {
        const payload = {
          name: this.schemaName,
          venue_id: this.venueId,
          schema: this.schema
        };
        
        const response = await axios.post('/api/venues/schemas', payload);
        
        if (response.data.success) {
          alert('Схема успешно сохранена!');
          this.showSaveModal = false;
          this.$emit('schema-saved', response.data.data);
        } else {
          throw new Error('Ошибка сохранения');
        }
      } catch (error) {
        console.error('Save error:', error);
        alert('Ошибка при сохранении схемы: ' + (error.response?.data?.message || error.message));
      }
    }
  }
};
</script>

<style scoped>
.hall-editor-container {
  width: 100%;
  height: 600px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  overflow: hidden;
  background: #f8fafc;
}

.editor-toolbar {
  display: flex;
  gap: 8px;
  padding: 12px;
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  align-items: center;
}

.tool-btn {
  padding: 8px 16px;
  border: 1px solid #cbd5e1;
  background: #fff;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.2s;
}

.tool-btn:hover {
  background: #f1f5f9;
}

.tool-btn.primary {
  background: #3b82f6;
  color: white;
  border-color: #3b82f6;
}

.tool-btn.primary:hover {
  background: #2563eb;
}

.tool-btn.danger {
  background: #ef4444;
  color: white;
  border-color: #ef4444;
}

.tool-btn.danger:hover {
  background: #dc2626;
}

.zoom-controls {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 8px;
}

.editor-canvas {
  width: 100%;
  height: calc(600px - 60px);
  overflow: auto;
  padding: 20px;
  position: relative;
}

.hall-row {
  display: flex;
  align-items: center;
  margin-bottom: 12px;
}

.row-label {
  width: 60px;
  font-weight: 600;
  color: #64748b;
  font-size: 14px;
}

.seats-container {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.seat {
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #e2e8f0;
  border: 2px solid #cbd5e1;
  border-radius: 4px;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  transition: all 0.2s;
}

.seat:hover {
  background: #cbd5e1;
}

.seat.selected {
  background: #3b82f6;
  color: white;
  border-color: #2563eb;
}

.seat.vip {
  background: #fbbf24;
  border-color: #f59e0b;
  color: #78350f;
}

.seat.standard {
  background: #e2e8f0;
  border-color: #cbd5e1;
}

.context-menu {
  position: fixed;
  background: white;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
  z-index: 1000;
  min-width: 150px;
}

.context-menu button {
  display: block;
  width: 100%;
  padding: 8px 12px;
  text-align: left;
  background: none;
  border: none;
  cursor: pointer;
  font-size: 14px;
}

.context-menu button:hover {
  background: #f1f5f9;
}

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
  z-index: 2000;
}

.modal {
  background: white;
  padding: 24px;
  border-radius: 8px;
  min-width: 400px;
}

.modal h3 {
  margin-bottom: 16px;
  font-size: 18px;
  color: #1e293b;
}

.input-field {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  margin-bottom: 16px;
  font-size: 14px;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.btn-secondary {
  padding: 8px 16px;
  border: 1px solid #cbd5e1;
  background: #fff;
  border-radius: 4px;
  cursor: pointer;
}

.btn-primary {
  padding: 8px 16px;
  background: #3b82f6;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}
</style>
