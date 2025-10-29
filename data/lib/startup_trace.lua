local _trace = {}

local traceEnabled = false
local envFlag = os.getenv('NEXUS_TRACE_STARTUP')
if envFlag == '1' or envFlag == 'true' then
    traceEnabled = true
end

local function tnow()
    return os.clock()
end

function _trace.checkpoint(name)
    if traceEnabled then
        print(string.format('[LUA-CKPT] %s @ %.3fs', name, tnow()))
    end
end

local _dofile = dofile
function dofile(path)
    if not traceEnabled then
        return _dofile(path)
    end

    local t0 = tnow()
    local results = {pcall(_dofile, path)}
    local ok = table.remove(results, 1)
    local duration = (tnow() - t0) * 1000
    if ok then
        print(string.format('[LUA-LOAD] %s => OK (%.1f ms)', path, duration))
        return table.unpack(results)
    end

    local err = results[1]
    print(string.format('[LUA-LOAD] %s => ERR: %s (%.1f ms)', path, tostring(err), duration))
    error(err)
end

return _trace

