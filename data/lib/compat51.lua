local debug = debug

if not table.unpack then
        table.unpack = unpack
end

if not unpack then
        function unpack(list, i, j)
                return table.unpack(list, i, j)
        end
end

if not table.maxn then
        function table.maxn(t)
                local max = 0
                for k in pairs(t) do
                        if type(k) == "number" and k > max then
                                max = k
                        end
                end
                return max
        end
end

local function normalize32(n)
        if n < 0 then
                n = (0x100000000 + (n % 0x100000000)) % 0x100000000
        end
        return n & 0xFFFFFFFF
end

bit32 = bit32 or {}

if not bit32.band then
        function bit32.band(x, ...)
                x = normalize32(x or -1)
                local count = select("#", ...)
                for i = 1, count do
                        x = x & normalize32(select(i, ...))
                end
                return normalize32(x)
        end
end

if not bit32.bor then
        function bit32.bor(x, ...)
                x = normalize32(x or 0)
                local count = select("#", ...)
                for i = 1, count do
                        x = x | normalize32(select(i, ...))
                end
                return normalize32(x)
        end
end

if not bit32.bxor then
        function bit32.bxor(x, ...)
                x = normalize32(x or 0)
                local count = select("#", ...)
                for i = 1, count do
                        x = x ~ normalize32(select(i, ...))
                end
                return normalize32(x)
        end
end

if not bit32.bnot then
        function bit32.bnot(x)
                return normalize32(~normalize32(x or 0))
        end
end

if not bit32.lshift then
        function bit32.lshift(x, disp)
                disp = disp & 31
                return normalize32((normalize32(x or 0) << disp))
        end
end

if not bit32.rshift then
        function bit32.rshift(x, disp)
                disp = disp & 31
                return (normalize32(x or 0) >> disp) & 0xFFFFFFFF
        end
end

if not bit32.arshift then
        function bit32.arshift(x, disp)
                disp = disp & 31
                local value = normalize32(x or 0)
                if value & 0x80000000 ~= 0 then
                        return normalize32((value >> disp) | ((0xFFFFFFFF << (32 - disp)) & 0xFFFFFFFF))
                end
                return normalize32(value >> disp)
        end
end

if not bit32.extract then
        function bit32.extract(x, field, width)
                width = width or 1
                return bit32.rshift(x, field) & ((1 << width) - 1)
        end
end

if not bit32.replace then
        function bit32.replace(x, v, field, width)
                width = width or 1
                local mask = ((1 << width) - 1) << field
                return bit32.band(x, ~mask) | ((v << field) & mask)
        end
end

local function findenv(func)
        local level = 1
        while true do
                local name = debug.getupvalue(func, level)
                if name == nil then
                        break
                end
                if name == "_ENV" then
                        return level
                end
                level = level + 1
        end
        return nil
end

if not setfenv then
        function setfenv(f, env)
                local func = type(f) == "function" and f or debug.getinfo(f + 1, "f").func
                local up = findenv(func)
                if up then
                        local proxy = function()
                                return env
                        end
                        debug.upvaluejoin(func, up, proxy, 1)
                        debug.setupvalue(func, up, env)
                end
                return func
        end
end

if not getfenv then
        function getfenv(f)
                local func = type(f) == "function" and f or debug.getinfo(f + 1, "f").func
                local up = findenv(func)
                if up then
                        local _, env = debug.getupvalue(func, up)
                        return env
                end
                return _G
        end
end

if not module then
        function module()
                return true
        end
end

return true
