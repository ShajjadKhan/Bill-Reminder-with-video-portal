#!/usr/bin/env python3
import asyncio
import websockets
import json
import time

MAX_VIEWERS = 30
viewers = {}
stream_url = None

async def handler(websocket, path):
    global stream_url
    viewer_id = None
    
    try:
        async for message in websocket:
            data = json.loads(message)
            
            if data["type"] == "join":
                viewer_id = data["viewerId"]
                
                if len(viewers) >= MAX_VIEWERS and viewer_id not in viewers:
                    await websocket.send(json.dumps({"type": "busy"}))
                    return
                
                viewers[viewer_id] = {"socket": websocket, "joined": time.time()}
                await broadcast_count()
                
                if stream_url:
                    await websocket.send(json.dumps({"type": "stream", "url": stream_url}))
                
            elif data["type"] == "stream_ready" and "url" in data:
                stream_url = data["url"]
                await broadcast_stream()
                
    except:
        pass
    finally:
        if viewer_id and viewer_id in viewers:
            del viewers[viewer_id]
            await broadcast_count()

async def broadcast_count():
    count = len(viewers)
    message = json.dumps({"type": "count", "count": count})
    for v in list(viewers.values()):
        try:
            await v["socket"].send(message)
        except:
            pass

async def broadcast_stream():
    if not stream_url:
        return
    message = json.dumps({"type": "stream", "url": stream_url})
    for v in list(viewers.values()):
        try:
            await v["socket"].send(message)
        except:
            pass

async def main():
    async with websockets.serve(handler, "0.0.0.0", 8085):
        print(f"WebSocket server running on port 8085 (max viewers: {MAX_VIEWERS})")
        await asyncio.Future()

if __name__ == "__main__":
    asyncio.run(main())
