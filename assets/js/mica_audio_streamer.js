/**
 * MICA Audio Streamer
 *
 * A self-contained ES6 class that manages WebSocket real-time audio streaming.
 * Handles auto-reconnect, binary PCM Int16 message dispatching, and exposes
 * non-breaking callbacks for integration with existing MICA voice flow.
 *
 * Usage:
 *   const streamer = new MicaAudioStreamer({
 *     wsUrl: 'wss://your-backend/ws/audio',
 *     onLevel: (rms) => { /* optional RMS meter *\/ }
 *   });
 *   streamer.start();   // begin capturing + streaming
 *   streamer.stop();    // stop capturing + disconnect
 */
class MicaAudioStreamer {
  constructor(options = {}) {
    this.wsUrl = options.wsUrl || '';
    this.onLevel = typeof options.onLevel === 'function' ? options.onLevel : null;
    this.onOpen = typeof options.onOpen === 'function' ? options.onOpen : null;
    this.onClose = typeof options.onClose === 'function' ? options.onClose : null;
    this.onError = typeof options.onError === 'function' ? options.onError : null;
    this.onAudio = typeof options.onAudio === 'function' ? options.onAudio : null;

    this._audioContext = null;
    this._mediaStream = null;
    this._workletNode = null;
    this._ws = null;
    this._reconnectTimer = null;
    this._reconnectAttempts = 0;
    this._maxReconnectAttempts = 10;
    this._reconnectBaseDelay = 1000;
    this._isRunning = false;
    this._chunksSent = 0;
    this._bytesSent = 0;
  }

  get isRunning() {
    return this._isRunning;
  }

  async start() {
    if (this._isRunning) return;

    try {
      this._mediaStream = await navigator.mediaDevices.getUserMedia({
        audio: {
          echoCancellation: true,
          noiseSuppression: true,
          sampleRate: 16000,
          channelCount: 1,
        },
      });
    } catch (err) {
      const msg = err && err.message ? err.message : String(err);
      if (this.onError) this.onError('Microphone access denied or unavailable: ' + msg);
      throw err;
    }

    this._audioContext = new (window.AudioContext || window.webkitAudioContext)({
      sampleRate: 16000,
    });

    await this._audioContext.audioWorklet.addModule(
      (typeof MICA_AUDIO_WORKLET_URL !== 'undefined' && MICA_AUDIO_WORKLET_URL)
        ? MICA_AUDIO_WORKLET_URL
        : 'assets/js/mica_audio_worklet.js'
    );

    const source = this._audioContext.createMediaStreamSource(this._mediaStream);
    this._workletNode = new AudioWorkletNode(this._audioContext, 'mica-audio-capture', {
      numberOfOutputs: 0,
      numberOfInputs: 1,
    });

    this._workletNode.port.onmessage = (event) => {
      const msg = event.data;
      if (msg && msg.type === 'audio') {
        const int16 = new Int16Array(msg.data);
        const rms = this._computeRMS(int16);
        if (this.onLevel) this.onLevel(rms);
        this._sendBinary(int16);
        if (this.onAudio) this.onAudio(int16);
      }
    };

    source.connect(this._workletNode);
    this._workletNode.connect(this._audioContext.destination);

    this._isRunning = true;
    this._reconnectAttempts = 0;
    this._connectWebSocket();
  }

  stop() {
    this._isRunning = false;

    if (this._reconnectTimer) {
      clearTimeout(this._reconnectTimer);
      this._reconnectTimer = null;
    }

    if (this._workletNode) {
      try { this._workletNode.disconnect(); } catch (e) { /* ignore */ }
      this._workletNode = null;
    }

    if (this._mediaStream) {
      this._mediaStream.getTracks().forEach((track) => track.stop());
      this._mediaStream = null;
    }

    if (this._audioContext && this._audioContext.state !== 'closed') {
      try { this._audioContext.close(); } catch (e) { /* ignore */ }
      this._audioContext = null;
    }

    if (this._ws) {
      try { this._ws.close(); } catch (e) { /* ignore */ }
      this._ws = null;
    }
  }

  _connectWebSocket() {
    if (!this.wsUrl || !this._isRunning) return;

    try {
      this._ws = new WebSocket(this.wsUrl);
      this._ws.binaryType = 'arraybuffer';
    } catch (err) {
      if (this._isRunning) this._scheduleReconnect();
      return;
    }

    this._ws.onopen = () => {
      this._reconnectAttempts = 0;
      if (this.onOpen) this.onOpen();
    };

    this._ws.onclose = () => {
      if (this.onClose) this.onClose();
      if (this._isRunning) this._scheduleReconnect();
    };

    this._ws.onerror = () => {
      if (this.onError) this.onError('WebSocket error');
      if (this._ws) {
        try { this._ws.close(); } catch (e) { /* ignore */ }
      }
    };

    this._ws.onmessage = (event) => {
      // Control messages from backend (JSON) are passed through.
      // Binary messages are ignored by the streamer itself;
      // consume them via onAudio if needed.
      if (typeof event.data === 'string') {
        try {
          const data = JSON.parse(event.data);
          if (this.onAudio) this.onAudio(data);
        } catch (e) { /* ignore non-JSON text */ }
      }
    };
  }

  _scheduleReconnect() {
    if (!this._isRunning) return;
    if (this._reconnectAttempts >= this._maxReconnectAttempts) {
      if (this.onError) this.onError('Max reconnect attempts reached');
      return;
    }

    const delay = this._reconnectBaseDelay * Math.pow(2, this._reconnectAttempts);
    this._reconnectAttempts++;

    this._reconnectTimer = setTimeout(() => {
      this._connectWebSocket();
    }, delay);
  }

  _sendBinary(int16) {
    if (!this._ws || this._ws.readyState !== WebSocket.OPEN) return;

    try {
      this._ws.send(int16.buffer);
      this._chunksSent++;
      this._bytesSent += int16.byteLength;
    } catch (e) { /* ignore send errors */ }
  }

  _computeRMS(int16) {
    let sum = 0;
    for (let i = 0; i < int16.length; i++) {
      const sample = int16[i] / 0x8000;
      sum += sample * sample;
    }
    return Math.sqrt(sum / int16.length);
  }

  getStats() {
    return {
      chunksSent: this._chunksSent,
      bytesSent: this._bytesSent,
      reconnectAttempts: this._reconnectAttempts,
      isRunning: this._isRunning,
      wsReadyState: this._ws ? this._ws.readyState : 0,
    };
  }
}

// Export for module usage or attach to window for legacy script inclusion.
if (typeof module !== 'undefined' && module.exports) {
  module.exports = MicaAudioStreamer;
} else if (typeof window !== 'undefined') {
  window.MicaAudioStreamer = MicaAudioStreamer;
}
