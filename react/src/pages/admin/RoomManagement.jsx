import { useNotificationTarget } from '../../hooks/useNotificationTarget';
import React, { useState, useEffect, useMemo, useRef } from 'react';
import { Plus, Edit, Trash2, Search, Filter, ChevronDown, BedDouble, Upload, X } from 'lucide-react';
import roomService from '../../services/roomService';
import amenityService from '../../services/amenityService';
import { PageSkeletonLoader, usePageCache } from '../../components/ProtectedRoute';
import ConfirmDialog from '../../components/ConfirmDialog';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import './RoomManagement.css';
import Pagination from '../../components/Pagination';
import { usePagination } from '../../hooks/usePagination';

// Room type pricing - AUTO FILL
const ROOM_TYPE_PRICING = {
  'executive_suite': { price: 3000, price_day_tour: 1950, capacity: 3 },
  'family':          { price: 3500, price_day_tour: 2275, capacity: 5 },
  'deluxe':          { price: 1500, price_day_tour: 975, capacity: 2 },
  'superior_twin':   { price: 1000, price_day_tour: 650, capacity: 2 },
  'superior_queen':  { price: 1000, price_day_tour: 650, capacity: 2 },
  'premier':         { price: 2000, price_day_tour: 1300, capacity: 2 },
};

const NUMERIC_ONLY_STATUSES = ['available', 'cleaning', 'maintenance'];

const showToast = (message, type = 'success') => {
  const toast = document.createElement('div');
  toast.className = `simple-toast toast-${type}`;
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => toast.classList.add('show'), 10);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => { if (toast.parentNode) document.body.removeChild(toast); }, 300);
  }, 3000);
};

/**
 * Returns true if the only difference between the editing room and formData
 * is the status field. Used to decide which endpoint to call on save.
 */
const isStatusOnlyChange = (editingRoom, formData) => {
  if (!editingRoom) return false;

  const fields = ['room_number', 'room_type', 'capacity', 'price_per_night', 'floor', 'description'];
  const nonStatusChanged = fields.some((key) => {
    const original = editingRoom[key] ?? '';
    const current  = formData[key]    ?? '';
    // Compare as strings to avoid int/string mismatch
    return String(original) !== String(current);
  });

  if (nonStatusChanged) return false;

  // Also check amenities (order-insensitive)
  const origAmenities = Array.isArray(editingRoom.amenities) ? [...editingRoom.amenities].sort() : [];
  const currAmenities = Array.isArray(formData.amenities)    ? [...formData.amenities].sort()    : [];
  if (JSON.stringify(origAmenities) !== JSON.stringify(currAmenities)) return false;

  // Also check image — if a new image was uploaded, it's a full update
  if (formData.room_image) return false;

  // Check if image was removed (existing_image cleared)
  const hadImage    = !!editingRoom.images?.[0];
  const stillHasImg = !!formData.existing_image;
  if (hadImage && !stillHasImg) return false;

  // At this point all other fields are identical — only status could differ
  return editingRoom.status !== formData.status;
};

const RoomManagement = () => {
  // ── Room list state ──
  const [rooms, setRooms] = useState([]);
  useNotificationTarget((target) => { setSearch(target.search); setTypeFilter(''); setStatusFilter(''); });
  const [search, setSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');

  // ── Modal / form state ──
  const [showModal, setShowModal] = useState(false);
  const [confirmDialog, setConfirmDialog] = useState({
    open: false,
    type: null,
    payload: null,
    title: '',
    message: '',
  });
  const [editingRoom, setEditingRoom] = useState(null);
  const [formData, setFormData] = useState({
    room_number:    '',
    room_type:      'deluxe',
    capacity:       2,
    price_per_night: 1500,
    price_day_tour: 975,
    floor:          '',
    status:         'available',
    description:    '',
    amenities:      [],
    room_image:     null,
    existing_image: null,
  });
  const [errors, setErrors]           = useState({});
  const [imagePreview, setImagePreview] = useState(null);

  // ── Amenity state ──
  const [allAmenities, setAllAmenities]       = useState([]);
  const [amenitiesLoading, setAmenitiesLoading] = useState(false);
  const [newAmenityInput, setNewAmenityInput]   = useState('');
  const [newAmenityError, setNewAmenityError]   = useState('');
  const [addingAmenity, setAddingAmenity]       = useState(false);
  const [deletingAmenityId, setDeletingAmenityId] = useState(null);
  const newAmenityInputRef = useRef(null);

  const { shouldShowSkeleton, markPageAsLoaded } = usePageCache('rooms');
  const [loading, setLoading] = useState(shouldShowSkeleton);
  const refreshIntervalRef    = useRef(null);

  const CACHE_KEY      = 'roomManagement_cache';
  const CACHE_DURATION = 30 * 1000;

  // ── Derived flags ──
  const isOccupied          = editingRoom?.status?.toLowerCase() === 'occupied';
  const disableRoomNumber   = isOccupied;
  const disableFloor        = isOccupied;
  const disableStatus       = false;
  const disableCapacity     = true;
  const disablePrice        = true;
  const isNumericOnlyStatus = NUMERIC_ONLY_STATUSES.includes(formData.status);

  // ─────────────────────────────────────────────
  //  Cache helpers
  // ─────────────────────────────────────────────
  const isCacheValid = () => {
    const cache = localStorage.getItem(CACHE_KEY);
    if (!cache) return false;
    try { const { timestamp } = JSON.parse(cache); return Date.now() - timestamp < CACHE_DURATION; }
    catch { return false; }
  };

  const getCachedData = () => {
    const cache = localStorage.getItem(CACHE_KEY);
    if (!cache) return null;
    try { return JSON.parse(cache).data; } catch { return null; }
  };

  const saveToCache = (data) =>
    localStorage.setItem(CACHE_KEY, JSON.stringify({ data, timestamp: Date.now() }));

  const clearCache = () => localStorage.removeItem(CACHE_KEY);

  // ─────────────────────────────────────────────
  //  Toast styles injection
  // ─────────────────────────────────────────────
  useEffect(() => {
    const styleId = 'simple-toast-styles';
    if (!document.getElementById(styleId)) {
      const style = document.createElement('style');
      style.id = styleId;
      style.textContent = `
        .simple-toast{position:fixed;top:20px;right:20px;padding:16px 24px;border-radius:8px;
          color:#fff;font-size:14px;font-weight:500;box-shadow:0 10px 40px rgba(0,0,0,.2);
          z-index:9999;transform:translateX(400px);opacity:0;transition:all .3s ease;}
        .simple-toast.show{transform:translateX(0);opacity:1;}
        .toast-success{background:#10b981;}
        .toast-error{background:#ef4444;}
      `;
      document.head.appendChild(style);
    }
  }, []);

  // ─────────────────────────────────────────────
  //  Load amenities from backend
  // ─────────────────────────────────────────────
  const loadAmenities = async () => {
    setAmenitiesLoading(true);
    try {
      const res = await amenityService.getAmenities();
      setAllAmenities(res.data || []);
    } catch {
      showToast('Failed to load amenities', 'error');
    } finally {
      setAmenitiesLoading(false);
    }
  };

  // ─────────────────────────────────────────────
  //  Add a new custom amenity
  // ─────────────────────────────────────────────
  const handleAddAmenity = async () => {
    const name = newAmenityInput.trim();
    if (!name) {
      setNewAmenityError('Amenity name cannot be empty.');
      return;
    }
    if (name.length > 100) {
      setNewAmenityError('Amenity name must be 100 characters or fewer.');
      return;
    }

    const duplicate = allAmenities.some(
      (a) => a.name.toLowerCase() === name.toLowerCase()
    );
    if (duplicate) {
      setNewAmenityError(`"${name}" already exists.`);
      return;
    }

    setAddingAmenity(true);
    setNewAmenityError('');
    try {
      const res = await amenityService.createAmenity(name);
      const created = res.data;
      setAllAmenities((prev) => [...prev, created]);
      setFormData((prev) => ({
        ...prev,
        amenities: [...(Array.isArray(prev.amenities) ? prev.amenities : []), created.name],
      }));
      setNewAmenityInput('');
      showToast(`"${created.name}" added!`, 'success');
      newAmenityInputRef.current?.focus();
    } catch (err) {
      setNewAmenityError(err.response?.data?.message || 'Failed to add amenity.');
    } finally {
      setAddingAmenity(false);
    }
  };

  // ─────────────────────────────────────────────
  //  Delete a custom amenity
  // ─────────────────────────────────────────────
  const handleDeleteAmenity = async (amenity) => {
    setConfirmDialog({
      open: true,
      type: 'amenity',
      payload: amenity,
      title: 'Delete Amenity',
      message: `Delete amenity "${amenity.name}"? This won't affect rooms that already have it.`,
    });
  };

  const performDeleteAmenity = async (amenity) => {
    setDeletingAmenityId(amenity.id);
    try {
      await amenityService.deleteAmenity(amenity.id);
      setAllAmenities((prev) => prev.filter((a) => a.id !== amenity.id));
      setFormData((prev) => ({
        ...prev,
        amenities: (Array.isArray(prev.amenities) ? prev.amenities : []).filter(
          (a) => a !== amenity.name
        ),
      }));
      showToast(`"${amenity.name}" deleted.`, 'success');
    } catch (err) {
      showToast(err.response?.data?.message || 'Failed to delete amenity.', 'error');
    } finally {
      setDeletingAmenityId(null);
    }
  };

  // ─────────────────────────────────────────────
  //  Rooms load / cache
  // ─────────────────────────────────────────────
  useEffect(() => {
    loadAmenities();

    if (typeFilter || statusFilter) {
      loadRooms();
    } else {
      if (isCacheValid()) {
        const cachedRooms = getCachedData();
        if (cachedRooms) {
          setRooms(cachedRooms);
          setLoading(false);
          markPageAsLoaded();
        } else {
          loadRooms();
        }
      } else {
        loadRooms();
      }
    }

    refreshIntervalRef.current = setInterval(() => loadRooms(true), 10000);
    return () => { if (refreshIntervalRef.current) clearInterval(refreshIntervalRef.current); };
  }, [typeFilter, statusFilter]);

  const loadRooms = async (silent = false) => {
    try {
      if (!silent && shouldShowSkeleton) setLoading(true);
      const params = {
        room_type: typeFilter || undefined,
        status:    statusFilter || undefined,
      };
      const response   = await roomService.getRooms(params);
      const roomData   = response.data.data || response.data || [];
      const roomsArray = Array.isArray(roomData) ? roomData : [];
      setRooms(roomsArray);
      if (!typeFilter && !statusFilter) saveToCache(roomsArray);
      if (!silent) {
        markPageAsLoaded();
        await new Promise((r) => setTimeout(r, 300));
      }
    } catch (error) {
      console.error('Error loading rooms:', error);
      if (!silent) { setRooms([]); showToast('Failed to load rooms', 'error'); }
    } finally {
      if (!silent && shouldShowSkeleton) setLoading(false);
    }
  };

  // ─────────────────────────────────────────────
  //  Filtering + pagination
  // ─────────────────────────────────────────────
  const filtered = useMemo(() => {
    if (!Array.isArray(rooms)) return [];
    return rooms.filter((room) => {
      if (!room) return false;
      const s = search.toLowerCase();
      return (
        (room.room_number && room.room_number.toLowerCase().includes(s)) ||
        (room.room_type   && room.room_type.toLowerCase().includes(s))   ||
        (room.status      && room.status.toLowerCase().includes(s))
      );
    });
  }, [rooms, search]);

  const {
    currentPage, totalPages, itemsPerPage, paginatedData, totalItems,
    handlePageChange, handleItemsPerPageChange, resetPage,
  } = usePagination(filtered, 10);

  useEffect(() => { resetPage(); }, [search]);

  // ─────────────────────────────────────────────
  //  Form handlers
  // ─────────────────────────────────────────────
  const handleRoomTypeChange = (newRoomType) => {
    const pricing = ROOM_TYPE_PRICING[newRoomType];
    if (pricing) {
      setFormData((prev) => ({
        ...prev,
        room_type:       newRoomType,
        price_per_night: pricing.price,
        price_day_tour:  pricing.price_day_tour,
        capacity:        pricing.capacity,
      }));
    }
  };

  const handleRoomNumberChange = (e) => {
    const value = isNumericOnlyStatus ? e.target.value.replace(/\D/g, '') : e.target.value;
    setFormData((prev) => ({ ...prev, room_number: value }));
    if (errors.room_number) setErrors((prev) => ({ ...prev, room_number: null }));
  };

  const handleFloorChange = (e) => {
    const value = isNumericOnlyStatus ? e.target.value.replace(/\D/g, '') : e.target.value;
    setFormData((prev) => ({ ...prev, floor: value }));
    if (errors.floor) setErrors((prev) => ({ ...prev, floor: null }));
  };

  const handleImageUpload = (e) => {
    const file = e.target.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { showToast('Image must be less than 5MB', 'error'); return; }
    const reader = new FileReader();
    reader.onloadend = () => setImagePreview(reader.result);
    reader.readAsDataURL(file);
    setFormData((prev) => ({ ...prev, room_image: file }));
  };

  const removeImage = () => {
    setFormData((prev) => ({
      ...prev,
      room_image:     null,
      existing_image: editingRoom ? null : prev.existing_image,
    }));
    setImagePreview(null);
  };

  const handleAmenityToggle = (amenityName) => {
    setFormData((prev) => {
      const current = Array.isArray(prev.amenities) ? prev.amenities : [];
      return {
        ...prev,
        amenities: current.includes(amenityName)
          ? current.filter((a) => a !== amenityName)
          : [...current, amenityName],
      };
    });
  };

  // ─────────────────────────────────────────────
  //  Submit — smart routing:
  //    status-only change → PATCH /rooms/{id}/status
  //    any other change   → PUT  /rooms/{id}
  // ─────────────────────────────────────────────
  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrors({});

    if (isNumericOnlyStatus) {
      const newErrors = {};
      if (formData.room_number && !/^\d+$/.test(formData.room_number))
        newErrors.room_number = ['Room number must contain numbers only.'];
      if (formData.floor && !/^\d+$/.test(String(formData.floor)))
        newErrors.floor = ['Floor must contain numbers only.'];
      if (Object.keys(newErrors).length > 0) {
        setErrors(newErrors);
        showToast(Object.values(newErrors).flat()[0], 'error');
        return;
      }
    }

    try {
      if (editingRoom && isStatusOnlyChange(editingRoom, formData)) {
        // ── Status-only path: PATCH /admin/rooms/{id}/status ──
        await roomService.updateRoomStatus(editingRoom.id, formData.status);
        showToast(`Room ${editingRoom.room_number} status updated to "${formData.status}"!`, 'success');
      } else {
        // ── Full update path: PUT /admin/rooms/{id} ──
        const submitData = {
          room_number:     formData.room_number,
          room_type:       formData.room_type,
          capacity:        parseInt(formData.capacity),
          price_per_night: parseFloat(formData.price_per_night),
          price_day_tour:  formData.price_day_tour ? parseFloat(formData.price_day_tour) : null,
          floor:           formData.floor ? parseInt(formData.floor) : null,
          status:          formData.status,
          description:     formData.description || '',
          amenities:       Array.isArray(formData.amenities) ? formData.amenities : [],
          room_image:      formData.room_image,
        };
        if (editingRoom && formData.existing_image && !formData.room_image) submitData.keep_image = formData.existing_image;

        if (editingRoom) {
          await roomService.updateRoom(editingRoom.id, submitData);
          showToast(`Room ${submitData.room_number} updated successfully!`, 'success');
        } else {
          await roomService.createRoom(submitData);
          showToast(`Room ${submitData.room_number} created successfully!`, 'success');
        }
      }

      setShowModal(false);
      resetForm();
      clearCache();
      loadRooms();
    } catch (error) {
      console.error('Submit error:', error.response?.data);
      if (error.response?.data?.errors) {
        setErrors(error.response.data.errors);
        showToast(Object.values(error.response.data.errors).flat()[0] || 'Validation failed', 'error');
      } else {
        showToast(error.response?.data?.message || 'Failed to save room', 'error');
      }
    }
  };

  // ─────────────────────────────────────────────
  //  Edit / Delete room
  // ─────────────────────────────────────────────
  const handleEdit = (room) => {
    setEditingRoom(room);
    setFormData({
      room_number:     room.room_number    || '',
      room_type:       room.room_type      || 'deluxe',
      capacity:        room.capacity       || 2,
      price_per_night: room.price_per_night || '',
      price_day_tour:  room.price_day_tour  || '',
      floor:           room.floor          || '',
      status:          room.status         || 'available',
      description:     room.description   || '',
      amenities:       Array.isArray(room.amenities) ? room.amenities : [],
      room_image:      null,
      existing_image:  (() => {
        // Prefer raw path from images array; fall back to stripping base URL from image_urls
        if (room.images && Array.isArray(room.images) && room.images[0]) {
          return room.images[0];
        }
        if (room.image_urls && room.image_urls[0]) {
          // Strip domain + /storage/ prefix to get raw path e.g. rooms/file.jpg
          return room.image_urls[0].replace(/^.*\/storage\//, '');
        }
        return null;
      })(),
    });
    setImagePreview(room.image_urls?.[0] || (room.images?.[0] ? `${window.location.origin}/storage/${room.images[0]}` : null));

    if (Array.isArray(room.amenities)) {
      setAllAmenities((prev) => {
        const existing = prev.map((a) => a.name.toLowerCase());
        const extras = room.amenities
          .filter((name) => !existing.includes(name.toLowerCase()))
          .map((name) => ({ id: `legacy-${name}`, name, is_default: false }));
        return [...prev, ...extras];
      });
    }

    setShowModal(true);
  };

  const handleDelete = async (room) => {
    setConfirmDialog({
      open: true,
      type: 'room',
      payload: room,
      title: 'Delete Room',
      message: `Are you sure you want to delete Room ${room.room_number}?`,
    });
  };

  const performDeleteRoom = async (room) => {
    try {
      await roomService.deleteRoom(room.id);
      showToast(`Room ${room.room_number} deleted successfully!`, 'success');
      clearCache();
      loadRooms();
    } catch (error) {
      showToast(error.response?.data?.message || 'Failed to delete room', 'error');
    }
  };

  const handleConfirmAction = async () => {
    const { type, payload } = confirmDialog;
    if (!type || !payload) return;

    setConfirmDialog((prev) => ({ ...prev, open: false }));

    if (type === 'amenity') {
      await performDeleteAmenity(payload);
      return;
    }

    if (type === 'room') {
      await performDeleteRoom(payload);
    }
  };

  const resetForm = () => {
    setFormData({
      room_number: '', room_type: 'deluxe',   capacity: 2, price_per_night: 1500, price_day_tour: 975,
      floor: '', status: 'available', description: '', amenities: [],
      room_image: null, existing_image: null,
    });
    setEditingRoom(null);
    setErrors({});
    setImagePreview(null);
    setNewAmenityInput('');
    setNewAmenityError('');
  };

  const openAddModal = () => { resetForm(); setShowModal(true); };

  if (loading) return <PageSkeletonLoader title="Room Management" />;

  // ─────────────────────────────────────────────
  //  Render
  // ─────────────────────────────────────────────
  return (
    <div className="room-management-page">

      {/* ── Page header ── */}
      <div className="page-header">
        <div>
          <h1>Room Management</h1>
          <p className="page-subtitle">Manage your hotel rooms and availability</p>
        </div>
        <button className="btn-primary" onClick={openAddModal}>
          <Plus size={20} /> Add New Room
        </button>
      </div>

      {/* ── Toolbar ── */}
      <div className="page-toolbar">
        <div className="search-wrap">
          <Search size={16} />
          <input
            type="text"
            placeholder="Search by room number, type, or status…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <div className="filter-wrap">
          <Filter size={16} />
          <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)}>
            <option value="">All Types</option>
            <option value="executive_suite">Executive Suite</option>
            <option value="family">Family Room</option>
            <option value="deluxe">Deluxe</option>
            <option value="superior_twin">Superior Twin</option>
            <option value="superior_queen">Superior Queen</option>
            <option value="premier">Premier</option>
          </select>
          <ChevronDown size={14} className="select-chevron" />
        </div>
        <div className="filter-wrap">
          <Filter size={16} />
          <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            <option value="">All Status</option>
            <option value="available">Available</option>
            <option value="occupied">Occupied</option>
            <option value="maintenance">Maintenance</option>
            <option value="cleaning">Cleaning</option>
          </select>
          <ChevronDown size={14} className="select-chevron" />
        </div>
      </div>

      {/* ── Table ── */}
      <div className="table-card">
        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr>
                <th>Room Number</th><th>Type</th><th>Capacity</th>
                <th>Floor</th><th>Price/Night</th><th>Status</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {paginatedData.length === 0 ? (
                <tr><td colSpan="7" className="empty-row">No rooms found</td></tr>
              ) : (
                paginatedData.map((room) => (
                  <tr key={room.id}>
                    <td className="room-name-cell">Room {room.room_number}</td>
                    <td><span className="type-badge">{room.room_type}</span></td>
                    <td>
                      <span className="capacity-cell">
                        <BedDouble size={14} /> {room.capacity} guests
                      </span>
                    </td>
                    <td>{room.floor || 'N/A'}</td>
                    <td className="price-cell">₱{parseFloat(room.price_per_night).toLocaleString()}</td>
                    <td>
                      <StatusBadge status={room.status} />
                    </td>
                    <td>
                      <div className="action-group">
                        <TableActionButton iconOnly label="Edit room" onClick={() => handleEdit(room)}>
                          <Edit size={15} />
                        </TableActionButton>
                        <TableActionButton iconOnly tone="danger" label="Delete room" onClick={() => handleDelete(room)}>
                          <Trash2 size={15} />
                        </TableActionButton>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {paginatedData.length > 0 && (
          <Pagination
            currentPage={currentPage} totalPages={totalPages} totalItems={totalItems}
            itemsPerPage={itemsPerPage} onPageChange={handlePageChange}
            onItemsPerPageChange={handleItemsPerPageChange} pageSizeOptions={[10, 25, 50, 100]}
          />
        )}
      </div>

      {/* ── Modal ── */}
      {showModal && (
        <div className="modal-overlay" onClick={() => setShowModal(false)}>
          <div className="modal-content modal-large" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h2>{editingRoom ? 'Edit Room' : 'Add New Room'}</h2>
              <button className="modal-close" onClick={() => setShowModal(false)}>×</button>
            </div>

            <form onSubmit={handleSubmit}>
              <div className="form-grid">

                {/* Room Number */}
                <div className="form-group">
                  <label>Room Number *</label>
                  <input
                    type="text" value={formData.room_number}
                    onChange={handleRoomNumberChange} disabled={disableRoomNumber}
                    placeholder="e.g. 101" required
                  />
                  {errors.room_number && <span className="error">{errors.room_number[0]}</span>}
                </div>

                {/* Room Type */}
                <div className="form-group">
                  <label>Room Type *</label>
                  <select value={formData.room_type} onChange={(e) => handleRoomTypeChange(e.target.value)} required>
                    <option value="executive_suite">Executive Suite</option>
                    <option value="family">Family Room</option>
                    <option value="deluxe">Deluxe</option>
                    <option value="superior_twin">Superior Twin</option>
                    <option value="superior_queen">Superior Queen</option>
                    <option value="premier">Premier</option>
                  </select>
                </div>

                {/* Capacity */}
                <div className="form-group">
                  <label>Capacity (Guests) *</label>
                  <input type="number" min="1" value={formData.capacity}
                    onChange={(e) => setFormData({ ...formData, capacity: e.target.value })}
                    disabled={disableCapacity} required />
                </div>

                {/* Price */}
                <div className="form-group">
                  <label>Price Per Night (₱) *</label>
                  <input type="number" step="0.01" min="0" value={formData.price_per_night}
                    onChange={(e) => setFormData({ ...formData, price_per_night: e.target.value })}
                    disabled={disablePrice} required />
                </div>

                {/* Day Tour Price */}
                <div className="form-group">
                  <label>Day Tour Price (₱) <span style={{fontSize:'0.8em', color:'#888'}}>optional — 12-hr walk-in rate</span></label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={formData.price_day_tour || ''}
                    placeholder="Leave blank to auto-calculate (65% of overnight)"
                    onChange={(e) => setFormData({ ...formData, price_day_tour: e.target.value })}
                  />
                </div>

                {/* Floor */}
                <div className="form-group">
                  <label>Floor</label>
                  <input type="text" value={formData.floor} onChange={handleFloorChange}
                    disabled={disableFloor} placeholder="e.g. 1" />
                  {errors.floor && <span className="error">{errors.floor[0]}</span>}
                </div>

                {/* Status */}
                <div className="form-group">
                  <label>Status *</label>
                  <select value={formData.status}
                    onChange={(e) => setFormData({ ...formData, status: e.target.value })}
                    disabled={disableStatus} required>
                    {editingRoom?.status === 'occupied' && (
                      <option value="occupied" disabled>Occupied (system controlled)</option>
                    )}
                    <option value="available">Available</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="cleaning">Cleaning</option>
                  </select>
                  {editingRoom?.status === 'occupied' && (
                    <p className="field-help">Occupied is controlled by guest check-in. Choose Available only after the guest is no longer checked in.</p>
                  )}
                  {!editingRoom && (
                    <p className="field-help">New rooms cannot start as Occupied. Occupancy is set automatically at check-in.</p>
                  )}
                </div>

                {/* Description */}
                <div className="form-group full-width">
                  <label>Description</label>
                  <textarea rows="3" value={formData.description}
                    onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                    placeholder="Enter room description..." />
                </div>

                {/* ── Amenities ── */}
                <div className="form-group full-width">
                  <label>Amenities</label>

                  {amenitiesLoading ? (
                    <p className="amenities-loading">Loading amenities…</p>
                  ) : (
                    <>
                      <div className="amenities-grid">
                        {allAmenities.map((amenity) => (
                          <label key={amenity.id} className="checkbox-label amenity-checkbox-item">
                            <input
                              type="checkbox"
                              checked={
                                Array.isArray(formData.amenities) &&
                                formData.amenities.includes(amenity.name)
                              }
                              onChange={() => handleAmenityToggle(amenity.name)}
                            />
                            <span className="amenity-name">{amenity.name}</span>

                            {!amenity.is_default && (
                              <button
                                type="button"
                                className="amenity-delete-btn"
                                title={`Remove "${amenity.name}"`}
                                disabled={deletingAmenityId === amenity.id}
                                onClick={(e) => {
                                  e.preventDefault();
                                  e.stopPropagation();
                                  handleDeleteAmenity(amenity);
                                }}
                              >
                                {deletingAmenityId === amenity.id ? '…' : <X size={11} />}
                              </button>
                            )}
                          </label>
                        ))}
                      </div>

                      {/* ── Add Amenity row ── */}
                      <div className="add-amenity-row">
                        <input
                          ref={newAmenityInputRef}
                          type="text"
                          className="add-amenity-input"
                          placeholder="New amenity name…"
                          value={newAmenityInput}
                          maxLength={100}
                          onChange={(e) => {
                            setNewAmenityInput(e.target.value);
                            if (newAmenityError) setNewAmenityError('');
                          }}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter') { e.preventDefault(); handleAddAmenity(); }
                          }}
                        />
                        <button
                          type="button"
                          className="add-amenity-btn"
                          onClick={handleAddAmenity}
                          disabled={addingAmenity}
                        >
                          <Plus size={14} />
                          {addingAmenity ? 'Adding…' : 'Add Amenity'}
                        </button>
                      </div>
                      {newAmenityError && (
                        <span className="error amenity-error">{newAmenityError}</span>
                      )}
                    </>
                  )}
                </div>

                {/* Room Image */}
                <div className="form-group full-width">
                  <label>Room Image</label>
                  {imagePreview ? (
                    <div className="image-preview-single">
                      <img src={imagePreview} alt="Room" />
                      <button type="button" className="remove-image-btn-small"
                        onClick={removeImage} title="Remove image">
                        <X size={14} />
                      </button>
                    </div>
                  ) : (
                    <div className="upload-compact-wrapper">
                      <input type="file" id="room-image" accept="image/*"
                        onChange={handleImageUpload} style={{ display: 'none' }} />
                      <label htmlFor="room-image" className="upload-btn-compact">
                        <Upload size={14} /> Upload Image
                      </label>
                      <span className="upload-hint">PNG, JPG (Max 5MB)</span>
                    </div>
                  )}
                </div>

              </div>{/* /form-grid */}

              <div className="modal-footer">
                <button type="button" className="btn-secondary" onClick={() => setShowModal(false)}>
                  Cancel
                </button>
                <button type="submit" className="btn-primary">
                  {editingRoom ? 'Update Room' : 'Create Room'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      <ConfirmDialog
        open={confirmDialog.open}
        title={confirmDialog.title}
        message={confirmDialog.message}
        confirmLabel="Delete"
        cancelLabel="Cancel"
        danger
        onCancel={() => setConfirmDialog({ open: false, type: null, payload: null, title: '', message: '' })}
        onConfirm={handleConfirmAction}
      />
    </div>
  );
};

export default RoomManagement;
