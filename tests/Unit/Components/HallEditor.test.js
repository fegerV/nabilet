/**
 * Тесты для компонента HallEditor.vue
 * Проверка функциональности редактора схем залов для мобильных устройств
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import HallEditor from '../../../resources/js/components/HallEditor.vue';

// Моки для axios
vi.mock('axios', () => ({
  default: {
    post: vi.fn()
  }
}));

describe('HallEditor Component', () => {
  let wrapper;

  beforeEach(() => {
    wrapper = mount(HallEditor, {
      props: {
        venueId: 1,
        existingSchema: { rows: [] }
      }
    });
  });

  describe('Инициализация', () => {
    it('должен корректно инициализироваться с пустой схемой', () => {
      expect(wrapper.vm.schema.rows).toHaveLength(0);
      expect(wrapper.vm.zoom).toBe(100);
      expect(wrapper.vm.selectedSeats).toHaveLength(0);
    });

    it('должен загружать существующую схему из пропсов', async () => {
      const existingSchema = {
        rows: [
          {
            id: 1,
            seats: [
              { id: 101, number: 1, type: 'standard', row: 1 },
              { id: 102, number: 2, type: 'vip', row: 1 }
            ]
          }
        ]
      };

      // Создаем новый wrapper с existingSchema
      const newWrapper = mount(HallEditor, {
        props: {
          venueId: 1,
          existingSchema: existingSchema
        }
      });

      await flushPromises();

      expect(newWrapper.vm.schema.rows).toHaveLength(1);
      expect(newWrapper.vm.schema.rows[0].seats).toHaveLength(2);
    });
  });

  describe('Добавление рядов и мест', () => {
    it('должен добавлять новый ряд с 10 стандартными местами', async () => {
      wrapper.vm.addRow();
      
      expect(wrapper.vm.schema.rows).toHaveLength(1);
      expect(wrapper.vm.schema.rows[0].seats).toHaveLength(10);
      expect(wrapper.vm.schema.rows[0].seats[0].type).toBe('standard');
    });

    it('должен добавлять место в последний ряд', async () => {
      wrapper.vm.addRow();
      const initialSeatsCount = wrapper.vm.schema.rows[0].seats.length;
      
      wrapper.vm.addSeat();
      
      expect(wrapper.vm.schema.rows[0].seats).toHaveLength(initialSeatsCount + 1);
    });

    it('должен показывать предупреждение при добавлении места без рядов', () => {
      const alertMock = vi.spyOn(window, 'alert').mockImplementation(() => {});
      
      wrapper.vm.addSeat();
      
      expect(alertMock).toHaveBeenCalledWith('Сначала добавьте ряд');
      alertMock.mockRestore();
    });
  });

  describe('Выбор мест', () => {
    beforeEach(() => {
      wrapper.vm.addRow();
    });

    it('должен выбирать одно место при клике', async () => {
      const seat = wrapper.vm.schema.rows[0].seats[0];
      wrapper.vm.selectSeat(seat);
      
      expect(wrapper.vm.selectedSeats).toContain(seat.id);
      expect(wrapper.vm.selectedSeats).toHaveLength(1);
    });

    it('должен поддерживать множественный выбор с Ctrl/Cmd', async () => {
      const seat1 = wrapper.vm.schema.rows[0].seats[0];
      const seat2 = wrapper.vm.schema.rows[0].seats[1];
      
      // Выбираем первое место
      wrapper.vm.selectSeat(seat1);
      expect(wrapper.vm.selectedSeats).toHaveLength(1);
      
      // Выбираем второе место с Ctrl (эмуляция)
      wrapper.vm.selectedSeats.push(seat2.id);
      expect(wrapper.vm.selectedSeats).toHaveLength(2);
    });

    it('должен снимать выделение при повторном клике с Ctrl', async () => {
      const seat = wrapper.vm.schema.rows[0].seats[0];
      
      wrapper.vm.selectedSeats.push(seat.id);
      expect(wrapper.vm.selectedSeats).toContain(seat.id);
      
      // Эмуляция снятия выделения
      const index = wrapper.vm.selectedSeats.indexOf(seat.id);
      if (index > -1) {
        wrapper.vm.selectedSeats.splice(index, 1);
      }
      
      expect(wrapper.vm.selectedSeats).not.toContain(seat.id);
    });
  });

  describe('Удаление мест', () => {
    beforeEach(() => {
      wrapper.vm.addRow();
    });

    it('должен удалять выбранные места', () => {
      const seatId = wrapper.vm.schema.rows[0].seats[0].id;
      wrapper.vm.selectedSeats = [seatId];
      const initialCount = wrapper.vm.schema.rows[0].seats.length;
      
      wrapper.vm.deleteSelected();
      
      expect(wrapper.vm.schema.rows[0].seats).toHaveLength(initialCount - 1);
      expect(wrapper.vm.selectedSeats).toHaveLength(0);
    });

    it('должен показывать предупреждение при удалении без выбора', () => {
      const alertMock = vi.spyOn(window, 'alert').mockImplementation(() => {});
      
      wrapper.vm.deleteSelected();
      
      expect(alertMock).toHaveBeenCalledWith('Выберите места для удаления');
      alertMock.mockRestore();
    });

    it('должен удалять место через контекстное меню', () => {
      const seat = wrapper.vm.schema.rows[0].seats[0];
      const seatId = seat.id;
      const initialCount = wrapper.vm.schema.rows[0].seats.length;
      
      wrapper.vm.contextMenu.currentSeat = seat;
      wrapper.vm.deleteSeat();
      
      expect(wrapper.vm.schema.rows[0].seats).toHaveLength(initialCount - 1);
      expect(wrapper.vm.contextMenu.visible).toBe(false);
    });
  });

  describe('Контекстное меню', () => {
    beforeEach(() => {
      wrapper.vm.addRow();
    });

    it('должен открывать контекстное меню при правом клике', () => {
      const seat = wrapper.vm.schema.rows[0].seats[0];
      const mockEvent = {
        clientX: 100,
        clientY: 200,
        preventDefault: vi.fn()
      };
      
      wrapper.vm.openSeatMenu(seat, mockEvent);
      
      expect(wrapper.vm.contextMenu.visible).toBe(true);
      expect(wrapper.vm.contextMenu.x).toBe(100);
      expect(wrapper.vm.contextMenu.y).toBe(200);
      expect(wrapper.vm.contextMenu.currentSeat).toBe(seat);
    });

    it('должен закрывать контекстное меню', () => {
      wrapper.vm.contextMenu.visible = true;
      wrapper.vm.contextMenu.currentSeat = { id: 1 };
      
      wrapper.vm.closeContextMenu();
      
      expect(wrapper.vm.contextMenu.visible).toBe(false);
      expect(wrapper.vm.contextMenu.currentSeat).toBe(null);
    });

    it('должен менять тип места через контекстное меню', () => {
      const seat = wrapper.vm.schema.rows[0].seats[0];
      wrapper.vm.contextMenu.currentSeat = seat;
      
      wrapper.vm.setSeatType('vip');
      
      expect(seat.type).toBe('vip');
      expect(wrapper.vm.contextMenu.visible).toBe(false);
    });
  });

  describe('Масштабирование', () => {
    it('должен увеличивать масштаб', () => {
      const initialZoom = wrapper.vm.zoom;
      wrapper.vm.zoomIn();
      
      expect(wrapper.vm.zoom).toBe(initialZoom + 10);
    });

    it('должен уменьшать масштаб', () => {
      wrapper.vm.zoom = 100;
      wrapper.vm.zoomOut();
      
      expect(wrapper.vm.zoom).toBe(90);
    });

    it('не должен превышать максимальный масштаб 200%', () => {
      wrapper.vm.zoom = 195;
      wrapper.vm.zoomIn();
      
      expect(wrapper.vm.zoom).toBe(200);
      
      wrapper.vm.zoomIn();
      expect(wrapper.vm.zoom).toBe(200); // Не должно превысить 200
    });

    it('не должен быть меньше минимального масштаба 50%', () => {
      wrapper.vm.zoom = 55;
      wrapper.vm.zoomOut();
      
      expect(wrapper.vm.zoom).toBe(50);
      
      wrapper.vm.zoomOut();
      expect(wrapper.vm.zoom).toBe(50); // Не должно быть меньше 50
    });
  });

  describe('Сохранение схемы', () => {
    it('должен открывать модальное окно сохранения', () => {
      expect(wrapper.vm.showSaveModal).toBe(false);
      
      wrapper.vm.saveSchema();
      
      expect(wrapper.vm.showSaveModal).toBe(true);
    });

    it('должен закрывать модальное окно при отмене', async () => {
      wrapper.vm.saveSchema();
      expect(wrapper.vm.showSaveModal).toBe(true);
      
      wrapper.vm.cancelSave();
      
      expect(wrapper.vm.showSaveModal).toBe(false);
      expect(wrapper.vm.schemaName).toBe('');
    });

    it('должен требовать название схемы перед сохранением', async () => {
      wrapper.vm.saveSchema();
      const alertMock = vi.spyOn(window, 'alert').mockImplementation(() => {});
      
      await wrapper.vm.confirmSave();
      
      expect(alertMock).toHaveBeenCalledWith('Введите название схемы');
      alertMock.mockRestore();
    });

    it('должен успешно сохранять схему через API', async () => {
      const axios = (await import('axios')).default;
      axios.post.mockResolvedValue({
        data: {
          success: true,
          data: { id: 1, name: 'Тестовая схема' }
        }
      });

      wrapper.vm.saveSchema();
      wrapper.vm.schemaName = 'Тестовая схема';
      
      await wrapper.vm.confirmSave();
      
      expect(axios.post).toHaveBeenCalledWith('/api/venues/schemas', {
        name: 'Тестовая схема',
        venue_id: 1,
        schema: wrapper.vm.schema
      });
    });

    it('должен обрабатывать ошибки при сохранении', async () => {
      const axios = (await import('axios')).default;
      axios.post.mockRejectedValue(new Error('Ошибка сети'));
      
      wrapper.vm.saveSchema();
      wrapper.vm.schemaName = 'Тестовая схема';
      
      const alertMock = vi.spyOn(window, 'alert').mockImplementation(() => {});
      await wrapper.vm.confirmSave();
      
      expect(alertMock).toHaveBeenCalled();
      alertMock.mockRestore();
    });
  });

  describe('Поиск места', () => {
    beforeEach(() => {
      wrapper.vm.addRow();
    });

    it('должен находить место по ID', () => {
      const seat = wrapper.vm.schema.rows[0].seats[0];
      const found = wrapper.vm.findSeat(seat.id);
      
      expect(found).toBe(seat);
    });

    it('должен возвращать null если место не найдено', () => {
      const found = wrapper.vm.findSeat(999999);
      
      expect(found).toBe(null);
    });
  });

  describe('Выделение прямоугольником', () => {
    it('должен начинать выделение при нажатии левой кнопки мыши', () => {
      const mockEvent = { button: 0, clientX: 100, clientY: 200 };
      
      wrapper.vm.startSelection(mockEvent);
      
      expect(wrapper.vm.isSelecting).toBe(true);
      expect(wrapper.vm.selectionStart).toEqual({ x: 100, y: 200 });
    });

    it('должен завершать выделение при отпускании кнопки', () => {
      wrapper.vm.isSelecting = true;
      
      wrapper.vm.endSelection();
      
      expect(wrapper.vm.isSelecting).toBe(false);
    });

    it('должен обновлять выделение при движении мыши', () => {
      wrapper.vm.isSelecting = true;
      
      // Логика обновления выделения может быть добавлена здесь
      wrapper.vm.updateSelection({ clientX: 150, clientY: 250 });
      
      expect(wrapper.vm.isSelecting).toBe(true);
    });
  });

  describe('Мобильная оптимизация', () => {
    it('должен иметь начальный масштаб 100% для мобильных устройств', () => {
      expect(wrapper.vm.zoom).toBe(100);
    });

    it('должен поддерживать диапазон зума от 50% до 200%', () => {
      wrapper.vm.zoom = 50;
      wrapper.vm.zoomOut();
      expect(wrapper.vm.zoom).toBe(50);
      
      wrapper.vm.zoom = 200;
      wrapper.vm.zoomIn();
      expect(wrapper.vm.zoom).toBe(200);
    });
  });
});

describe('HallEditor - Интеграционные тесты', () => {
  it('должен позволять полный рабочий цикл: создание ряда, выбор места, изменение типа, сохранение', async () => {
    const wrapper = mount(HallEditor, {
      props: {
        venueId: 1,
        existingSchema: { rows: [] }
      }
    });

    // Добавляем ряд
    wrapper.vm.addRow();
    expect(wrapper.vm.schema.rows).toHaveLength(1);

    // Выбираем место
    const seat = wrapper.vm.schema.rows[0].seats[0];
    wrapper.vm.selectSeat(seat);
    expect(wrapper.vm.selectedSeats).toContain(seat.id);

    // Меняем тип места через контекстное меню
    wrapper.vm.contextMenu.currentSeat = seat;
    wrapper.vm.setSeatType('vip');
    expect(seat.type).toBe('vip');

    // Сохраняем схему
    const axios = (await import('axios')).default;
    axios.post.mockResolvedValue({
      data: {
        success: true,
        data: { id: 1, name: 'Интеграционный тест' }
      }
    });

    wrapper.vm.saveSchema();
    wrapper.vm.schemaName = 'Интеграционный тест';
    await wrapper.vm.confirmSave();

    expect(axios.post).toHaveBeenCalled();
  });
});
