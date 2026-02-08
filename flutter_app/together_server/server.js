const { Server } = require("socket.io");

const io = new Server(3000, {
  cors: {
    origin: "*",
  },
});

// Store room states
// roomId -> { 
//   hostSocketId: string, 
//   currentPayload: object 
// }
const rooms = {};

// Helper to broadcast user list
function broadcastUserList(io, roomId) {
  if (!rooms[roomId]) {
    console.log(`[Debug] broadcastUserList: Room ${roomId} not found`);
    return;
  }
  const list = Array.from(rooms[roomId].users);
  const host = rooms[roomId].hostSocketId;

  // Debug Socket.IO internal room state
  const socketRoom = io.sockets.adapter.rooms.get(roomId);
  const socketRoomSize = socketRoom ? socketRoom.size : 0;
  console.log(`[Debug] broadcastUserList: Room ${roomId}, LogicUsers: ${list.length}, SocketRoomSize: ${socketRoomSize}, Host: ${host}`);

  const payload = { users: list, host: host };
  console.log(`[Debug] Emitting 'room_users' to ${roomId}`);

  // Broadcast strategy:
  // 1. Emit to the room (standard)
  io.to(roomId).emit("room_users", payload);

  // 2. FORCE EMIT to every individual user in the list (Robustness fix)
  // This ensures that even if 'socket.join' delayed or failed effectively, 
  // if the ID is in our list, we try to send it directly.
  list.forEach(userId => {
    console.log(`[Debug] Force emitting 'room_users' to Individual ${userId}`);
    io.to(userId).emit("room_users", payload);
  });
}

io.on("connection", (socket) => {
  console.log(`Socket connected: ${socket.id}`);

  // Map to track which room a socket is in (for disconnect)
  socket.roomId = null;

  // Create or Join Room
  socket.on("join_room", (roomId) => {
    // Leave previous room if any
    if (socket.roomId) {
      socket.leave(socket.roomId);
    }

    socket.join(roomId);
    socket.roomId = roomId;

    // Initialize room if not exists
    if (!rooms[roomId]) {
      rooms[roomId] = {
        hostSocketId: socket.id, // First joiner becomes host
        currentPayload: null,
        users: new Set([socket.id])
      };
      console.log(`Room created: ${roomId} by Host ${socket.id}`);
      socket.emit("room_joined", { isHost: true, roomId });
    } else {
      rooms[roomId].users.add(socket.id);
      console.log(`User ${socket.id} joined Room ${roomId}`);
      socket.emit("room_joined", { isHost: false, roomId });

      // Notify host that someone joined (to trigger welcome sync)
      io.to(rooms[roomId].hostSocketId).emit("user_joined", { userId: socket.id });
    }

    // DIRECT EMIT: Ensure the joining user gets the list via their socket instance (most reliable)
    const list = Array.from(rooms[roomId].users);
    const host = rooms[roomId].hostSocketId;
    console.log(`[Debug] Direct socket.emit 'room_users' to ${socket.id}`);
    socket.emit("room_users", { users: list, host: host });

    broadcastUserList(io, roomId);
  });

  // Listener requesting sync (reconnect scenario)
  socket.on("request_sync", (roomId) => {
    if (rooms[roomId] && rooms[roomId].hostSocketId) {
      console.log(`Sync requested by ${socket.id} in ${roomId}`);
      io.to(rooms[roomId].hostSocketId).emit("user_joined", { userId: socket.id, isSyncRequest: true });
      broadcastUserList(io, roomId); // Also refresh user list
    }
  });

  // Host Actions (Broadcast to all guests in room)
  socket.on("host_action", (data) => {
    // data: { roomId: string, type: string, payload: object }
    const { roomId, type, payload } = data;

    if (!rooms[roomId]) return;

    // Verify authority (simple check)
    // In production, we should check if socket.id === rooms[roomId].hostSocketId
    if (socket.id !== rooms[roomId].hostSocketId) {
      // Optional: Allow acting host? For now strict.
      // console.log('Unauthorized host action');
      // return; 
    }

    // Update server-side state cache
    if (type === 'change_song' || type === 'play' || type === 'pause' || type === 'seek') {
      rooms[roomId].currentPayload = payload;
    }

    // Broadcast to everyone ELSE in the room
    socket.to(roomId).emit("guest_sync", data);
    console.log(`Host Action in ${roomId}: ${type}`);
  });

  // Host Heartbeat (Broadcast position to correct drift)
  socket.on("host_heartbeat", (data) => {
    const { roomId, payload } = data;
    if (!rooms[roomId]) return;

    // Pass through to guests
    socket.to(roomId).emit("guest_heartbeat", data);
  });

  socket.on("disconnect", () => {
    console.log(`Socket disconnected: ${socket.id}`);
    const rId = socket.roomId;
    if (rId && rooms[rId]) {
      rooms[rId].users.delete(socket.id);

      if (rooms[rId].users.size === 0) {
        delete rooms[rId]; // Delete empty room
        console.log(`Room ${rId} deleted`);
      } else {
        // If host left, disband room
        if (rooms[rId].hostSocketId === socket.id) {
          console.log(`Host ${socket.id} left Room ${rId}. Disbanding.`);

          // Robust Broadcast: P2P Emit to all users in the room
          // (io.to(rId) might be unreliable)
          const roomUsers = Array.from(rooms[rId].users);
          roomUsers.forEach(uid => {
            if (uid !== socket.id) { // Don't send to self (already leaving)
              console.log(`[Debug] Force emitting 'room_closed' to ${uid}`);
              io.to(uid).emit("room_closed", { reason: "host_left" });
            }
          });

          delete rooms[rId];
        } else {
          broadcastUserList(io, rId);
        }
      }
    }
  });
});

console.log("Listen Together Server running on port 3000");
